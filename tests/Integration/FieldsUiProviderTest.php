<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Db\Internal\WpdbTableGateway;
use Iniznet\Mahout\Fields\Admin\FieldEditor as FieldEditorImplementation;
use Iniznet\Mahout\Fields\Admin\FieldStyles;
use Iniznet\Mahout\Fields\Admin\FieldsUiProvider;
use Iniznet\Mahout\Fields\Admin\FieldTypeRegistry;
use Iniznet\Mahout\Fields\Admin\Nonces;
use Iniznet\Mahout\Fields\Contracts\ControlRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldEditor as FieldEditorContract;
use Iniznet\Mahout\Fields\Contracts\FieldReader as FieldReaderContract;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry as FieldRegistryContract;
use Iniznet\Mahout\Fields\Contracts\FieldUiPolicy;
use Iniznet\Mahout\Fields\Contracts\FieldWriter as FieldWriterContract;
use Iniznet\Mahout\Fields\Contracts\Panels;
use Iniznet\Mahout\Fields\Contracts\RequestInput as RequestInputContract;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\FieldPanel;
use Iniznet\Mahout\Fields\FieldsProvider;
use Iniznet\Mahout\Fields\Hooks;
use Iniznet\Mahout\Fields\MirrorCodec;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\Fixtures\ArrayRequestInput;
use Iniznet\Mahout\Fields\Tests\Fixtures\DeclaredPanels;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;
use Iniznet\Mahout\Kernel\Container;
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The opt-in UI provider: the seams it owns, and the rule that the whole
 * editing surface is derived from `Contracts\Panels` and from nothing else.
 *
 * No panels binding and an empty declaration attach nothing at all; a declared
 * panel attaches the metabox for its (post type, group) pair, the classic save
 * entry with the handler's guard order intact, the value route with its read
 * bindings, and the write-failure notice -- and each of those is proved by
 * firing the hook and observing the screen, never by counting closures.
 *
 * @internal
 */
final class FieldsUiProviderTest extends TestCase
{
    private const string GROUP = 'fixture_group';

    public function testRegisterBindsTheUiSeamsUnderTheirContracts(): void
    {
        $container = $this->container();

        self::assertInstanceOf(ControlRegistry::class, $container->get(ControlRegistry::class));
        self::assertInstanceOf(FieldTypeRegistry::class, $container->get(ControlRegistry::class));
        self::assertInstanceOf(FieldEditorContract::class, $container->get(FieldEditorContract::class));
        self::assertInstanceOf(FieldEditorImplementation::class, $container->get(FieldEditorContract::class));
    }

    public function testBootsNothingWithoutAPanelsBinding(): void
    {
        $container = $this->container();

        $this->clearUiHooks();
        (new FieldsUiProvider())->boot($container);

        self::assertSame([], $this->attachedUiHooks(), 'no declaration, no attachment -- the opt-in and the empty case are one path');
    }

    public function testBootsNothingWhenNoPanelIsDeclared(): void
    {
        $container = $this->container(new DeclaredPanels());

        $this->clearUiHooks();
        (new FieldsUiProvider())->boot($container);

        self::assertSame([], $this->attachedUiHooks());
    }

    public function testEveryDeclaredSurfaceIsAttachedWhenAPanelIsDeclared(): void
    {
        $container = $this->container(new DeclaredPanels([new FieldPanel('post', $this->group())]));

        $this->clearUiHooks();
        (new FieldsUiProvider())->boot($container);

        self::assertSame(
            [Hooks::ADD_META_BOXES, Hooks::SAVE_POST, Hooks::REST_API_INIT, Hooks::ADMIN_NOTICES, Hooks::ADMIN_ENQUEUE_SCRIPTS],
            $this->attachedUiHooks(),
            'one metabox entry, one save entry, one REST entry, one notice entry, one stylesheet entry, and none beside them',
        );
    }

    public function testADeclaredPanelRegistersOneMetaboxForItsPostTypeAndGroup(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $container = $this->container(new DeclaredPanels([new FieldPanel('post', $this->group())]));
        (new FieldsUiProvider())->boot($container);

        $this->loadMetaBoxApi();
        $this->signInAsEditor();
        $GLOBALS['wp_meta_boxes'] = [];
        \do_action(Hooks::ADD_META_BOXES, 'post', get_post($postId));

        $registered = $GLOBALS['wp_meta_boxes']['post']['normal']['default'] ?? [];

        self::assertArrayHasKey('mahout-fields-'.self::GROUP, $registered, 'the panel registers its metabox on the screen it declares');
        self::assertSame('Fixture panel', $registered['mahout-fields-'.self::GROUP]['title'], 'the title is the declaration\'s label');
    }

    public function testAPanelIsNotRegisteredForAUserWithoutTheCapability(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $container = $this->container(new DeclaredPanels([new FieldPanel('post', $this->group())]));
        (new FieldsUiProvider())->boot($container);

        $this->loadMetaBoxApi();
        wp_set_current_user((int) self::factory()->user->create(['role' => 'subscriber']));
        $GLOBALS['wp_meta_boxes'] = [];
        \do_action(Hooks::ADD_META_BOXES, 'post', get_post($postId));

        self::assertSame([], $GLOBALS['wp_meta_boxes']['post'] ?? [], 'a panel the user cannot edit the post for is not registered at all');
    }

    public function testTheSaveEntryWritesTheDeclaredGroupThroughTheGuardOrder(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $this->signInAsEditor();

        $container = $this->container(
            new DeclaredPanels([new FieldPanel('post', $this->group())]),
            new ArrayRequestInput(
                body: [Nonces::nonceField() => \wp_create_nonce(Nonces::action($postId))],
                groups: [self::GROUP => ['fixture_text' => 'through the provider']],
                hashes: [self::GROUP => MirrorCodec::hash([])],
            ),
        );
        (new FieldsUiProvider())->boot($container);

        \do_action(Hooks::SAVE_POST, $postId, get_post($postId), true);

        self::assertSame(
            'through the provider',
            $this->reader->value('fixture_text', ObjectRef::post($postId)),
            'the classic entry writes through the container\'s own writer',
        );
    }

    public function testARefusedSaveSurfacesTheQueuedNoticeOnce(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $this->signInAsEditor();

        $container = $this->container(
            new DeclaredPanels([new FieldPanel('post', $this->group())]),
            new ArrayRequestInput(
                body: [Nonces::nonceField() => 'expired-or-forged'],
                groups: [self::GROUP => ['fixture_text' => 'never written']],
            ),
        );

        // Core's own admin notices need an admin screen; this screen is the
        // package's, so the notice is fired with nothing else attached.
        $this->clearUiHooks();
        (new FieldsUiProvider())->boot($container);

        \do_action(Hooks::SAVE_POST, $postId, get_post($postId), true);

        self::assertNull($this->reader->value('fixture_text', ObjectRef::post($postId)), 'a refusal writes nothing');

        $GLOBALS['post'] = get_post($postId);

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

    public function testTheValueRouteAndItsReadBindingsAreRegisteredOnRestInit(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $this->writer->set('fixture_text', ObjectRef::post($postId), 'in the representation');
        $this->signInAsEditor();

        $container = $this->container(new DeclaredPanels([new FieldPanel('post', $this->group())]));
        (new FieldsUiProvider())->boot($container);

        $GLOBALS['wp_rest_server'] = null;
        $server = \rest_get_server();

        self::assertArrayHasKey('/mahout-fields/v1/field-values', $server->get_routes(), 'the value route is bound');

        $response = $server->dispatch(new \WP_REST_Request('GET', '/wp/v2/posts/'.$postId));

        self::assertSame(200, $response->get_status());
        self::assertSame(
            'in the representation',
            $response->get_data()['mahout_fields_fixture_text'] ?? null,
            'the declared panel\'s field reaches the REST representation of its own post type',
        );
    }

    public function testAPostTypeThatDeclaresNoPanelGetsNoMetabox(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $container = $this->container(new DeclaredPanels([new FieldPanel('page', $this->group())]));
        (new FieldsUiProvider())->boot($container);

        $this->loadMetaBoxApi();
        $this->signInAsEditor();
        $GLOBALS['wp_meta_boxes'] = [];
        \do_action(Hooks::ADD_META_BOXES, 'post', get_post($postId));

        self::assertSame([], $GLOBALS['wp_meta_boxes']['post'] ?? [], 'the post screen declares no panel, so it gets no box');
    }

    public function testTheCoreProviderAttachesNoUiHookAtAll(): void
    {
        $container = new Container();
        $container->set($this->connection(), id: SqlConnection::class);
        $container->set(new WpdbTableGateway($this->connection()), id: TableGateway::class);
        $container->set($this->diagnostics(), id: Diagnostics::class);
        // A declaration is present: the storage core must still attach no
        // screen, because the UI is the other provider's and nothing else.
        $container->set(new DeclaredPanels([new FieldPanel('post', $this->group())]), id: Panels::class);

        $this->clearUiHooks();

        $core = new FieldsProvider();
        $core->register($container);
        $core->boot($container);

        self::assertSame([], $this->attachedUiHooks(), 'no dependency runs from the core provider to the UI, in either direction');
    }

    /**
     * A container in the state a real composition root is in when this
     * provider's register() runs: the storage core's three contracts bound,
     * the host's request and diagnostics adapters bound, the UI seams bound by
     * the provider itself, and `Contracts\Panels` bound only when declared.
     */
    private function container(?Panels $panels = null, ?RequestInputContract $request = null): Container
    {
        $container = new Container();
        $container->set(service: $this->registry, id: FieldRegistryContract::class);
        $container->set(service: $this->reader, id: FieldReaderContract::class);
        $container->set(service: $this->writer, id: FieldWriterContract::class);
        $container->set(service: $request ?? new ArrayRequestInput(), id: RequestInputContract::class);
        $container->set($this->diagnostics(), id: Diagnostics::class);

        if (null !== $panels) {
            $container->set($panels, id: Panels::class);
        }

        (new FieldsUiProvider())->register($container);

        return $container;
    }

    private function group(): FieldGroup
    {
        return new FieldGroup(self::GROUP, ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table, label: 'Text'),
        ], label: 'Fixture panel');
    }

    private function signInAsEditor(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
    }

    /** Core's metabox API is an admin include; the suite boots the front end. */
    private function loadMetaBoxApi(): void
    {
        require_once \ABSPATH.'wp-admin/includes/template.php';
    }

    /**
     * The four hooks this provider owns that carry one of its callbacks. The
     * order is the order the contract lists them in, and the assertion is that
     * the set is exactly the four -- nothing attached beside them.
     *
     * @return list<string>
     */
    public function testTheDefaultStylesheetIsEnqueuedOnADeclaredPanelsEditScreen(): void
    {
        $container = $this->container(new DeclaredPanels([new FieldPanel('post', $this->group())]));
        $container->set(new FieldStyles('http://example.org/vendor/mahout-fields/resources/fields.css', $container->get(Panels::class)), id: FieldStyles::class);

        $this->clearUiHooks();
        (new FieldsUiProvider())->boot($container);

        \set_current_screen('post.php');
        \get_current_screen()->post_type = 'post';

        \do_action(Hooks::ADMIN_ENQUEUE_SCRIPTS, 'post.php');

        self::assertTrue(\wp_style_is(FieldStyles::HANDLE, 'registered'), 'the handle exists only where field UI renders');
        self::assertTrue(\wp_style_is(FieldStyles::HANDLE, 'enqueued'));

        \wp_dequeue_style(FieldStyles::HANDLE);
        \wp_deregister_style(FieldStyles::HANDLE);
        \set_current_screen('front');
    }

    public function testTheDefaultStylesheetIsNotEnqueuedOnAnUnrelatedScreen(): void
    {
        $container = $this->container(new DeclaredPanels([new FieldPanel('post', $this->group())]));

        $this->clearUiHooks();
        (new FieldsUiProvider())->boot($container);

        \do_action(Hooks::ADMIN_ENQUEUE_SCRIPTS, 'edit-tags.php');

        self::assertFalse(\wp_style_is(FieldStyles::HANDLE, 'enqueued'));
    }

    public function testAPolicyThatTakesStylingOverRemovesTheStylesheetAndTheHook(): void
    {
        $policy = new class implements FieldUiPolicy {
            #[\Override]
            public function styled(): bool
            {
                return false;
            }

            #[\Override]
            public function fields(): array
            {
                return [];
            }
        };

        $container = $this->container(new DeclaredPanels([new FieldPanel('post', $this->group())]));
        $container->set($policy, id: FieldUiPolicy::class);

        $this->clearUiHooks();
        (new FieldsUiProvider())->boot($container);

        self::assertNotContains(Hooks::ADMIN_ENQUEUE_SCRIPTS, $this->attachedUiHooks(), 'a host that owns the pixels owns the enqueue too');

        \set_current_screen('post.php');
        \get_current_screen()->post_type = 'post';
        \do_action(Hooks::ADMIN_ENQUEUE_SCRIPTS, 'post.php');

        self::assertFalse(\wp_style_is(FieldStyles::HANDLE, 'registered'));

        \set_current_screen('front');
    }

    private function attachedUiHooks(): array
    {
        return array_values(array_filter(
            [Hooks::ADD_META_BOXES, Hooks::SAVE_POST, Hooks::REST_API_INIT, Hooks::ADMIN_NOTICES, Hooks::ADMIN_ENQUEUE_SCRIPTS],
            static fn (string $hook): bool => \has_action($hook) >= 1,
        ));
    }

    /**
     * Detach every prior callback from the four hooks, so an attachment is
     * attributed to this boot and to nothing else. Core's own attachments are
     * restored by the test case's hook backup.
     */
    private function clearUiHooks(): void
    {
        foreach ([Hooks::ADD_META_BOXES, Hooks::SAVE_POST, Hooks::REST_API_INIT, Hooks::ADMIN_NOTICES, Hooks::ADMIN_ENQUEUE_SCRIPTS] as $hook) {
            \remove_all_actions($hook);
        }
    }
}
