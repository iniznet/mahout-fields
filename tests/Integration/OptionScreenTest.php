<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\Admin\FieldsUiProvider;
use Iniznet\Mahout\Fields\Admin\Nonces;
use Iniznet\Mahout\Fields\Contracts\FieldReader as FieldReaderContract;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry as FieldRegistryContract;
use Iniznet\Mahout\Fields\Contracts\FieldWriter as FieldWriterContract;
use Iniznet\Mahout\Fields\Contracts\OptionScreens;
use Iniznet\Mahout\Fields\Contracts\RequestInput as RequestInputContract;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\Hooks;
use Iniznet\Mahout\Fields\MirrorCodec;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\OptionScreen;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\Fixtures\ArrayRequestInput;
use Iniznet\Mahout\Fields\Tests\Fixtures\DeclaredOptionScreens;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;
use Iniznet\Mahout\Kernel\Container;
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The option screens: the whole surface derives from the host's
 * `Contracts\OptionScreens` and from nothing else. No binding and an empty
 * declaration register nothing at all; a declared screen is registered only
 * for a user whose capability reaches it, renders through the same editor and
 * control pipeline the metaboxes use, and saves through the guard order with
 * the store step writing the option context through the real MetaStorage --
 * Meta target only, because the option context has no table rows.
 *
 * @internal
 */
final class OptionScreenTest extends TestCase
{
    private const string GROUP = 'fixture_group';

    private const string SLUG = 'fixture_screen';

    public function testTheProviderAttachesTheMenuEntryOnlyWhenAScreenIsDeclared(): void
    {
        $unbound = new Container();
        $unbound->set(service: $this->registry, id: FieldRegistryContract::class);
        $unbound->set(service: $this->reader, id: FieldReaderContract::class);
        $unbound->set(service: $this->writer, id: FieldWriterContract::class);
        $unbound->set(new ArrayRequestInput(), id: RequestInputContract::class);
        $unbound->set($this->diagnostics(), id: Diagnostics::class);
        (new FieldsUiProvider())->register($unbound);

        $this->clearMenuHooks();
        (new FieldsUiProvider())->boot($unbound);

        self::assertFalse(\has_action(Hooks::ADMIN_MENU), 'no declaration, no attachment -- the opt-in and the empty case are one path');

        $this->clearMenuHooks();
        (new FieldsUiProvider())->boot($this->container(new DeclaredOptionScreens()));

        self::assertFalse(\has_action(Hooks::ADMIN_MENU), 'an empty declaration attaches nothing at all');

        $this->clearMenuHooks();
        (new FieldsUiProvider())->boot($this->container(new DeclaredOptionScreens([$this->screen()])));

        self::assertTrue(\has_action(Hooks::ADMIN_MENU), 'the screens are the one host binding the menu entry is derived from');
    }

    public function testADeclaredScreenIsRegisteredOnAdminMenuForAUserWhoMayReachIt(): void
    {
        $this->registry->register($this->group());
        $this->signInAsAdministrator();

        $container = $this->container(new DeclaredOptionScreens([$this->screen()]));
        (new FieldsUiProvider())->boot($container);

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);

        self::assertContains(self::SLUG, $this->registeredSlugs(), 'the panel registers its page under the parent it declares');
        self::assertTrue(\has_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php'))), 'the page carries its save entry on its own load hook');
    }

    public function testAScreenIsNotRegisteredForAUserWithoutTheCapability(): void
    {
        $this->registry->register($this->group());
        wp_set_current_user((int) self::factory()->user->create(['role' => 'subscriber']));

        $container = $this->container(new DeclaredOptionScreens([$this->screen()]));
        (new FieldsUiProvider())->boot($container);

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);

        self::assertSame([], $this->registeredSlugs(), 'a screen the user cannot reach is not registered at all, never rendered without values');
        self::assertFalse(\has_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php'))), 'no page, no save entry');
    }

    public function testTheScreenRendersItsGroupThroughTheEditor(): void
    {
        $this->bootScreen();

        $this->writer->set('fixture_text', ObjectRef::option(), 'stored value');

        \ob_start();
        // The page renders through the callable add_submenu_page() attached
        // to the page's own hook; the load hook carries only the save entry.
        \do_action(\get_plugin_page_hookname(self::SLUG, 'options-general.php'));
        $markup = (string) \ob_get_clean();

        self::assertStringContainsString('data-mahout-group="'.self::GROUP.'"', $markup, 'the page renders through the same editor the metaboxes use');
        self::assertStringContainsString('stored value', $markup, 'the panel is built from the values the field layer reads');
        self::assertStringContainsString('name="'.Nonces::nonceField().'"', $markup, 'the form carries the nonce field the screen rendered');
        self::assertStringContainsString('name="'.Nonces::hashField().'['.self::GROUP.']"', $markup, 'the hash field rides the option panel like any other');
        self::assertStringNotContainsString('data-object-kind', $markup, 'the option context has no object kind: an option is a singleton, never a row');
        self::assertStringNotContainsString('data-object-id', $markup);
    }

    public function testTheSaveEntryWritesTheDeclaredGroupThroughTheOptionContext(): void
    {
        $this->registry->register($this->group());
        $this->signInAsAdministrator();

        $container = $this->container(
            new DeclaredOptionScreens([$this->screen()]),
            new ArrayRequestInput(
                body: [Nonces::nonceField() => \wp_create_nonce(Nonces::screenAction(self::SLUG))],
                groups: [self::GROUP => ['fixture_text' => 'saved through the screen']],
                hashes: [self::GROUP => MirrorCodec::hash([])],
            ),
        );
        (new FieldsUiProvider())->boot($container);

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);
        \do_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php')));

        self::assertSame(
            'saved through the screen',
            $this->reader->value('fixture_text', ObjectRef::option()),
            'the option context writes through the container\'s own writer',
        );
        self::assertSame(
            'saved through the screen',
            \get_option('mahout_fields/fixture_text'),
            'the value reaches the option store under the field\'s own key',
        );
    }

    public function testSanitisationRunsBeforeTheOptionIsStored(): void
    {
        $this->registry->register($this->group());
        $this->signInAsAdministrator();

        $container = $this->container(
            new DeclaredOptionScreens([$this->screen()]),
            new ArrayRequestInput(
                body: [Nonces::nonceField() => \wp_create_nonce(Nonces::screenAction(self::SLUG))],
                groups: [self::GROUP => ['fixture_text' => '<b>Sanitised</b>   value']],
            ),
        );
        (new FieldsUiProvider())->boot($container);

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);
        \do_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php')));

        self::assertSame(
            'Sanitised value',
            $this->reader->value('fixture_text', ObjectRef::option()),
            'the field\'s own sanitiser runs once, before the store step',
        );
    }

    public function testANonceRefusalLeavesTheOptionUntouchedAndSurfacesTheNoticeOnce(): void
    {
        $this->bootScreen(static fn (): ArrayRequestInput => new ArrayRequestInput(
            body: [Nonces::nonceField() => 'expired-or-forged'],
            groups: [self::GROUP => ['fixture_text' => 'never written']],
        ));

        \do_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php')));

        self::assertNull($this->reader->value('fixture_text', ObjectRef::option()), 'a refusal writes nothing');

        \ob_start();
        \do_action(Hooks::ADMIN_NOTICES);
        $notice = (string) \ob_get_clean();

        self::assertStringContainsString(self::GROUP, $notice, 'the refusal is surfaced for the user who submitted the form');
        self::assertStringContainsString('nonce_failed', $notice);

        \ob_start();
        \do_action(Hooks::ADMIN_NOTICES);
        $second = (string) \ob_get_clean();

        self::assertStringNotContainsString(self::GROUP, $second, 'the notice is one-shot');
    }

    public function testAnUnauthorisedSubmissionIsRefusedBeforeAnythingIsRead(): void
    {
        $this->bootScreen(static fn (): ArrayRequestInput => new ArrayRequestInput(
            body: [Nonces::nonceField() => \wp_create_nonce(Nonces::screenAction(self::SLUG))],
            groups: [self::GROUP => ['fixture_text' => 'never written']],
        ));

        wp_set_current_user((int) self::factory()->user->create(['role' => 'subscriber']));
        \do_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php')));

        self::assertNull($this->reader->value('fixture_text', ObjectRef::option()), 'a user who cannot reach the screen writes nothing');

        \ob_start();
        \do_action(Hooks::ADMIN_NOTICES);
        $notice = (string) \ob_get_clean();

        self::assertStringContainsString('authorization_denied', $notice, 'the capability guard is the save\'s own, not only the registration\'s');
    }

    public function testAFieldTheGroupDoesNotDeclareIsRefused(): void
    {
        $this->bootScreen(static fn (): ArrayRequestInput => new ArrayRequestInput(
            body: [Nonces::nonceField() => \wp_create_nonce(Nonces::screenAction(self::SLUG))],
            groups: [self::GROUP => ['fixture_undeclared' => 'mass assignment']],
        ));

        \do_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php')));

        self::assertNull($this->reader->value('fixture_text', ObjectRef::option()), 'a mass-assignment attempt writes nothing');

        \ob_start();
        \do_action(Hooks::ADMIN_NOTICES);
        $notice = (string) \ob_get_clean();

        self::assertStringContainsString('field_shape', $notice, 'the shape guard refuses before any value is sanitised or stored');
    }

    public function testASubmissionForAnotherGroupIsLeftUntouched(): void
    {
        $this->bootScreen(static fn (): ArrayRequestInput => new ArrayRequestInput(
            body: [Nonces::nonceField() => \wp_create_nonce(Nonces::screenAction(self::SLUG))],
            groups: ['fixture_other_group' => ['fixture_text' => 'never written']],
        ));

        \do_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php')));

        self::assertNull($this->reader->value('fixture_text', ObjectRef::option()), 'the screen writes its own group and no other');
    }

    public function testATableFieldIsRefusedForAnOptionGroupAtRegistration(): void
    {
        $this->registry->register($this->group());

        try {
            $this->registry->register(new FieldGroup('fixture_option_table', ObjectContext::Option, [
                new TextField('fixture_option_table_field', StorageTarget::Table),
            ]));
            self::fail('the option context has no table rows; a Table-bound field is refused at registration');
        } catch (InvalidStorageCombination) {
            self::addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------------

    /**
     * The full path a real composition root runs: the group registered, an
     * administrator signed in, the provider's two phases booted, the admin
     * API loaded and the menu action fired, so the screen, its save entry and
     * its notice exist exactly as they would on a live settings page. The
     * request fixture is built after the administrator signs in, because a
     * nonce is bound to the user who submits the form.
     *
     * @param \Closure|null $request builds the request under the signed-in user
     */
    private function bootScreen(?\Closure $request = null): void
    {
        $this->registry->register($this->group());
        $this->signInAsAdministrator();

        (new FieldsUiProvider())->boot($this->container(
            new DeclaredOptionScreens([$this->screen()]),
            null === $request ? null : $request(),
        ));

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);
    }

    /**
     * A container in the state a real composition root is in when this
     * provider's register() runs: the storage core's three contracts bound,
     * the host's request and diagnostics adapters bound, the UI seams bound by
     * the provider itself, and `Contracts\OptionScreens` bound only when the
     * host declares screens.
     */
    private function container(?OptionScreens $screens = null, ?ArrayRequestInput $request = null): Container
    {
        $container = new Container();
        $container->set(service: $this->registry, id: FieldRegistryContract::class);
        $container->set(service: $this->reader, id: FieldReaderContract::class);
        $container->set(service: $this->writer, id: FieldWriterContract::class);
        $container->set(service: $request ?? new ArrayRequestInput(), id: RequestInputContract::class);
        $container->set($this->diagnostics(), id: Diagnostics::class);

        if (null !== $screens) {
            $container->set($screens, id: OptionScreens::class);
        }

        (new FieldsUiProvider())->register($container);

        return $container;
    }

    private function group(): FieldGroup
    {
        return new FieldGroup(self::GROUP, ObjectContext::Option, [
            new TextField('fixture_text', StorageTarget::Meta, label: 'Text'),
        ], label: 'Fixture screen');
    }

    private function screen(): OptionScreen
    {
        return new OptionScreen(self::SLUG, 'Fixture options', 'Fixture fields', $this->group(), 'manage_options');
    }

    private function signInAsAdministrator(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
    }

    /** Core's settings-page API is an admin include; the suite boots the front end. */
    private function loadAdminApi(): void
    {
        require_once \ABSPATH.'wp-admin/includes/plugin.php';
        require_once \ABSPATH.'wp-admin/includes/template.php';
    }

    /** @return list<string> */
    private function registeredSlugs(): array
    {
        $GLOBALS['submenu'] = $GLOBALS['submenu'] ?? [];

        $slugs = [];

        foreach ((array) ($GLOBALS['submenu']['options-general.php'] ?? []) as $entry) {
            if (\is_array($entry) && \is_string($entry[2] ?? null)) {
                $slugs[] = $entry[2];
            }
        }

        return $slugs;
    }

    /**
     * Detach every prior callback from the hooks this surface owns, so an
     * attachment is attributed to this boot and to nothing else. Core's own
     * attachments are restored by the test case's hook backup.
     */
    private function clearMenuHooks(): void
    {
        \remove_all_actions(Hooks::ADMIN_MENU);
        \remove_all_actions(Hooks::ADMIN_NOTICES);
    }

    private function resetMenus(): void
    {
        $GLOBALS['submenu'] = [];
        $GLOBALS['menu'] = [];
    }
}
