<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\Admin\FieldStyles;
use Iniznet\Mahout\Fields\Admin\FieldsUiProvider;
use Iniznet\Mahout\Fields\Admin\Nonces;
use Iniznet\Mahout\Fields\Admin\OptionScreenManager;
use Iniznet\Mahout\Fields\Admin\WriteFailureNotice;
use Iniznet\Mahout\Fields\Contracts\FieldReader as FieldReaderContract;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry as FieldRegistryContract;
use Iniznet\Mahout\Fields\Contracts\FieldWriter as FieldWriterContract;
use Iniznet\Mahout\Fields\Contracts\OptionScreens;
use Iniznet\Mahout\Fields\Contracts\RequestInput as RequestInputContract;
use Iniznet\Mahout\Fields\Exception\InvalidPanelDeclaration;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\Hooks;
use Iniznet\Mahout\Fields\MirrorCodec;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\OptionScreen;
use Iniznet\Mahout\Fields\OptionScreenLayout;
use Iniznet\Mahout\Fields\OptionSection;
use Iniznet\Mahout\Fields\OptionTab;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\Fixtures\ArrayRequestInput;
use Iniznet\Mahout\Fields\Tests\Fixtures\DeclaredOptionScreens;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;
use Iniznet\Mahout\Kernel\Container;
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The option screen's page structure: tabs of sections, each section either
 * one group's fields or the declaring feature's own markup. A screen declares
 * its content one way -- its own group, normalised into the tab list, or
 * tabs, never both -- a screen whose tabs carry no field group renders no
 * form and gets no save entry, and the save writes exactly the active tab's
 * groups, group by group, in the order the sections render.
 *
 * @internal
 */
final class OptionScreenTabsTest extends TestCase
{
    private const string FIELDS_TAB = 'Fields';

    private const string GUIDE_TAB = 'Guide';

    private const string GROUP = 'fixture_group';

    private const string SECOND_GROUP = 'fixture_second_group';

    private const string SLUG = 'fixture_screen';

    public function testADeclaredGroupIsNormalisedIntoOneTab(): void
    {
        $screen = new OptionScreen(self::SLUG, 'Fixture options', 'Fixture fields', $this->group(), 'manage_options');

        self::assertCount(1, $screen->tabs, 'the group-only shape is the canonical single tab');
        self::assertSame(self::GROUP, $screen->fieldGroups()[0]->id);
        self::assertTrue($screen->hasFields());
    }

    public function testAScreenDeclaresItsContentOneWayOrNone(): void
    {
        try {
            new OptionScreen(self::SLUG, 'Fixture options', 'Fixture fields', null, 'manage_options');
            self::fail('a screen with no group and no tabs is a page that renders nothing');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertStringContainsString('neither a group nor tabs', $refusal->getMessage());
        }

        try {
            new OptionScreen(self::SLUG, 'Fixture options', 'Fixture fields', $this->group(), 'manage_options', tabs: [
                new OptionTab(self::FIELDS_TAB, [OptionSection::fields('', $this->group())]),
            ]);
            self::fail('a screen declares its group and tabs, never both');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertStringContainsString('inside a tab', $refusal->getMessage());
        }
    }

    public function testATabDeclaresALabelAndSections(): void
    {
        try {
            new OptionTab('', [OptionSection::fields('', $this->group())]);
            self::fail('the label is the tab\'s key in the URL');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertStringContainsString('no label', $refusal->getMessage());
        }

        try {
            new OptionTab(self::GUIDE_TAB, []);
            self::fail('an empty tab is a page that renders nothing');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertStringContainsString('no section', $refusal->getMessage());
        }
    }

    public function testAContentSectionNamesAMarkupFileThatExists(): void
    {
        try {
            OptionSection::content('Guide', '');
            self::fail('a section that names no markup file renders nothing');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertStringContainsString('names no markup file', $refusal->getMessage());
        }

        try {
            OptionSection::content('Guide', __DIR__.'/no-such-file.php');
            self::fail('the markup is part of the codebase, not a runtime condition');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertStringContainsString('does not exist', $refusal->getMessage());
        }
    }

    public function testATabFieldSectionIsRefusedForANonOptionGroup(): void
    {
        $post = new FieldGroup('fixture_post_group', ObjectContext::Post, [
            new TextField('fixture_post_text', StorageTarget::Meta),
        ]);

        try {
            new OptionScreen(self::SLUG, 'Fixture options', 'Fixture fields', null, 'manage_options', tabs: [
                new OptionTab(self::FIELDS_TAB, [OptionSection::fields('', $post)]),
            ]);
            self::fail('a post group on a settings page addresses an object that does not exist there');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertStringContainsString('option context', $refusal->getMessage());
        }
    }

    public function testTwoTabsRenderTheBarAndOnlyTheActiveTabsSections(): void
    {
        $this->bootScreen(params: ['tab' => self::GUIDE_TAB]);

        \ob_start();
        \do_action(\get_plugin_page_hookname(self::SLUG, 'options-general.php'));
        $markup = (string) \ob_get_clean();

        self::assertStringContainsString('nav-tab-wrapper', $markup, 'two tabs render core\'s own tab bar');
        self::assertStringContainsString('nav-tab-active', $markup, 'the active tab is marked');
        self::assertStringContainsString('Fixture guide markup', $markup, 'the active tab\'s content section renders');
        self::assertStringContainsString('data-mahout-panel="'.self::FIELDS_TAB.'" hidden', $markup, 'the inactive tab\'s panel renders hidden: one page load, the script swaps the visible panel');
        self::assertStringNotContainsString('data-mahout-panel="'.self::GUIDE_TAB.'" hidden', $markup, 'the active tab\'s panel is visible');
    }

    public function testAContentOnlyScreenRendersNoFormAndGetsNoSaveEntry(): void
    {
        // A documentation screen: every tab is content, no field group anywhere.
        $docs = new OptionScreen(
            'fixture_docs',
            'Fixture docs',
            'Fixture docs',
            null,
            'manage_options',
            tabs: [new OptionTab(self::GUIDE_TAB, [
                OptionSection::content('Guide', __DIR__.'/../Fixtures/markup/display-guide.php'),
            ])],
        );

        $this->registry->register($this->group());
        $this->signInAsAdministrator();
        (new FieldsUiProvider())->boot($this->container(
            new ArrayRequestInput(),
            [$docs],
        ));

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);

        \ob_start();
        \do_action(\get_plugin_page_hookname('fixture_docs', 'options-general.php'));
        $markup = (string) \ob_get_clean();

        self::assertStringContainsString('Fixture guide markup', $markup);
        self::assertStringNotContainsString('<form', $markup, 'nothing can be submitted to a screen that declares no fields');
        self::assertStringNotContainsString('name="'.Nonces::nonceField().'"', $markup, 'no form, no nonce');
        self::assertStringNotContainsString('submit', $markup);

        $hook = \get_plugin_page_hookname('fixture_docs', 'options-general.php');
        self::assertFalse(\has_action(Hooks::screenLoad($hook)), 'no fields, no save entry -- nothing can fail');
    }

    public function testTheScreenRendersItsStructure(): void
    {
        $this->bootScreen(params: ['tab' => self::FIELDS_TAB]);

        \ob_start();
        \do_action(\get_plugin_page_hookname(self::SLUG, 'options-general.php'));
        $markup = (string) \ob_get_clean();

        self::assertSame(1, substr_count($markup, '<form'), 'each fields panel is its own form: a tab that carries no fields renders none');
        self::assertSame(1, substr_count($markup, 'class="submit"'), 'one save button, on the one fields panel');
        self::assertSame(1, substr_count($markup, 'name="'.Nonces::nonceField().'"'), 'one nonce per form: the later field sections render none');
        self::assertStringContainsString('<h2>Fixture group</h2>', $markup, 'a titled section renders its heading');
        self::assertStringContainsString('<p class="description">Fixture options intro.</p>', $markup, 'the page\'s own intro renders');
        self::assertStringContainsString('type="hidden" name="'.OptionScreenManager::TAB_PARAM.'" value="'.self::FIELDS_TAB.'"', $markup, 'the form carries the active tab back');
    }

    public function testTheTabSwitchingScriptIsEnqueuedOnTheDeclaredScreen(): void
    {
        // This suite runs with no active theme, so the default asset
        // resolution refuses here by design: the URLs are bound, the way a
        // host that owns its mapping binds them.
        $this->registry->register($this->group());
        $this->registry->register($this->secondGroup());
        $this->signInAsAdministrator();

        $container = $this->container(new ArrayRequestInput(), [$this->tabbedScreen()]);
        $container->set(new FieldStyles(
            url: 'http://example.org/fields.css',
            scriptUrl: 'http://example.org/fields.js',
            screens: new DeclaredOptionScreens([$this->tabbedScreen()]),
        ), id: FieldStyles::class);

        (new FieldsUiProvider())->boot($container);

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);

        \set_current_screen('settings_page_'.self::SLUG);
        \do_action(Hooks::ADMIN_ENQUEUE_SCRIPTS, \get_plugin_page_hookname(self::SLUG, 'options-general.php'));

        self::assertTrue(\wp_style_is(FieldStyles::HANDLE, 'enqueued'), 'the shell ships its stylesheet');
        self::assertTrue(\wp_script_is(FieldStyles::SCRIPT_HANDLE, 'enqueued'), 'the tabs switch without a request: the script ships with the page');

        \wp_dequeue_style(FieldStyles::HANDLE);
        \wp_deregister_style(FieldStyles::HANDLE);
        \wp_dequeue_script(FieldStyles::SCRIPT_HANDLE);
        \wp_deregister_script(FieldStyles::SCRIPT_HANDLE);
        \set_current_screen('front');
    }

    public function testATopLevelScreenDeclaresItsOwnMenuAndNoParent(): void
    {
        try {
            new OptionScreen(self::SLUG, 'Fixture options', 'Fixture fields', null, 'manage_options', topLevel: true, tabs: [
                new OptionTab(self::GUIDE_TAB, [OptionSection::content('Guide', __DIR__.'/../Fixtures/markup/display-guide.php')]),
            ]);
            self::fail('a top-level menu declares its own icon');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertStringContainsString('no icon', $refusal->getMessage());
        }

        try {
            new OptionScreen(self::SLUG, 'Fixture options', 'Fixture fields', null, 'manage_options', menuParent: 'admin.php', topLevel: true, menuIcon: 'dashicons-admin-generic', tabs: [
                new OptionTab(self::GUIDE_TAB, [OptionSection::content('Guide', __DIR__.'/../Fixtures/markup/display-guide.php')]),
            ]);
            self::fail('a top-level screen declares no parent');
        } catch (InvalidPanelDeclaration $refusal) {
            self::assertStringContainsString('declares no parent', $refusal->getMessage());
        }
    }

    public function testATopLevelScreenRegistersItsOwnMenuEntry(): void
    {
        $docs = new OptionScreen(
            'fixture_docs',
            'Fixture docs',
            'Fixture docs',
            null,
            'manage_options',
            menuParent: '',
            topLevel: true,
            menuIcon: 'dashicons-admin-generic',
            tabs: [new OptionTab(self::GUIDE_TAB, [
                OptionSection::content('Guide', __DIR__.'/../Fixtures/markup/display-guide.php'),
            ])],
        );

        $this->registry->register($this->group());
        $this->signInAsAdministrator();
        (new FieldsUiProvider())->boot($this->container(
            new ArrayRequestInput(),
            [$docs],
        ));

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);

        $topLevel = array_column((array) ($GLOBALS['menu'] ?? []), 2);
        self::assertContains('fixture_docs', $topLevel, 'a top-level screen registers itself, not a submenu entry');
        self::assertFalse(\has_action(Hooks::screenLoad(\get_plugin_page_hookname('fixture_docs', ''))), 'a documentation page carries no save entry wherever it sits');
    }

    public function testASidebarScreenRendersItsNavigationColumn(): void
    {
        $screen = new OptionScreen(
            self::SLUG,
            'Fixture options',
            'Fixture fields',
            null,
            'manage_options',
            tabs: [
                new OptionTab(self::FIELDS_TAB, [
                    OptionSection::fields('Fixture group', $this->group()),
                ]),
                new OptionTab(self::GUIDE_TAB, [
                    OptionSection::content('Guide', __DIR__.'/../Fixtures/markup/display-guide.php'),
                ]),
            ],
            layout: OptionScreenLayout::Sidebar,
        );
        $this->registry->register($this->group());
        $this->registry->register($this->secondGroup());
        $this->signInAsAdministrator();
        (new FieldsUiProvider())->boot($this->container(
            new ArrayRequestInput(params: ['tab' => self::GUIDE_TAB]),
            [$screen],
        ));

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);

        \ob_start();
        \do_action(\get_plugin_page_hookname(self::SLUG, 'options-general.php'));
        $markup = (string) \ob_get_clean();

        self::assertStringContainsString('mahout-fields-page__grid', $markup, 'the sidebar layout is a two-column grid');
        self::assertStringContainsString('mahout-fields-page__nav-link', $markup, 'the tabs navigate as a column');
        self::assertStringContainsString('aria-current="true"', $markup, 'the active tab is marked');
        self::assertStringNotContainsString('nav-tab-wrapper', $markup, 'the declared layout is the one the page renders');
    }

    public function testThePageRendersItsContainer(): void
    {
        $this->bootScreen(params: ['tab' => self::FIELDS_TAB]);

        \ob_start();
        \do_action(\get_plugin_page_hookname(self::SLUG, 'options-general.php'));
        $markup = (string) \ob_get_clean();

        self::assertStringContainsString('mahout-fields-page__body', $markup, 'the page\'s content sits in its own container');
        self::assertStringContainsString('mahout-fields-page__section', $markup, 'each section is one block');
    }

    public function testTheSaveWritesEveryGroupOfTheActiveTab(): void
    {
        $this->registry->register($this->group());
        $this->registry->register($this->secondGroup());
        $this->signInAsAdministrator();

        $container = $this->container(
            new ArrayRequestInput(
                body: [Nonces::nonceField() => \wp_create_nonce(Nonces::screenAction(self::SLUG))],
                groups: [
                    self::GROUP => ['fixture_text' => 'first group saved'],
                    self::SECOND_GROUP => ['fixture_second_text' => 'second group saved'],
                ],
                hashes: [
                    self::GROUP => MirrorCodec::hash([]),
                    self::SECOND_GROUP => MirrorCodec::hash([]),
                ],
                params: ['tab' => self::FIELDS_TAB],
            ),
            [$this->tabbedScreen()],
        );
        (new FieldsUiProvider())->boot($container);

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);
        \do_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php')));

        self::assertSame('first group saved', $this->reader->value('fixture_text', ObjectRef::option()));
        self::assertSame('second group saved', $this->reader->value('fixture_second_text', ObjectRef::option()), 'every field section of the active tab is saved, in the order the sections render');
    }

    public function testTheFirstRefusalStopsTheScreensSubmission(): void
    {
        $this->registry->register($this->group());
        $this->registry->register($this->secondGroup());
        $this->signInAsAdministrator();

        // The second group submits a field id its declaration does not name:
        // the shape refusal stops the submission, and the notice carries it.
        $container = $this->container(
            new ArrayRequestInput(
                body: [Nonces::nonceField() => \wp_create_nonce(Nonces::screenAction(self::SLUG))],
                groups: [
                    self::GROUP => ['fixture_text' => 'kept'],
                    self::SECOND_GROUP => ['ghost_field' => 'refused'],
                ],
                hashes: [self::GROUP => MirrorCodec::hash([])],
                params: ['tab' => self::FIELDS_TAB],
            ),
            [$this->tabbedScreen()],
        );
        (new FieldsUiProvider())->boot($container);

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);
        \do_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php')));

        self::assertSame('kept', $this->reader->value('fixture_text', ObjectRef::option()));

        $taken = (new WriteFailureNotice())->takeForScreen(self::SLUG);
        self::assertNotNull($taken);
        self::assertSame(self::SECOND_GROUP, $taken->groupId);
        self::assertSame('field_shape', $taken->reason);
    }

    public function testTheSaveWritesOnlyTheActiveTabsGroups(): void
    {
        $this->registry->register($this->group());
        $this->registry->register($this->secondGroup());
        $this->signInAsAdministrator();

        $container = $this->container(
            new ArrayRequestInput(
                body: [Nonces::nonceField() => \wp_create_nonce(Nonces::screenAction(self::SLUG))],
                groups: [self::SECOND_GROUP => ['fixture_second_text' => 'never written']],
                params: ['tab' => self::GUIDE_TAB],
            ),
            [$this->tabbedScreen()],
        );
        (new FieldsUiProvider())->boot($container);

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);
        \do_action(Hooks::screenLoad(\get_plugin_page_hookname(self::SLUG, 'options-general.php')));

        self::assertNull($this->reader->value('fixture_second_text', ObjectRef::option()), 'a submission for another tab writes nothing');
    }

    // ------------------------------------------------------------------

    private function tabbedScreen(): OptionScreen
    {
        return new OptionScreen(
            self::SLUG,
            'Fixture options',
            'Fixture fields',
            null,
            'manage_options',
            description: 'Fixture options intro.',
            tabs: [
                new OptionTab(self::FIELDS_TAB, [
                    OptionSection::fields('Fixture group', $this->group()),
                    OptionSection::fields('Fixture second group', $this->secondGroup()),
                ]),
                new OptionTab(self::GUIDE_TAB, [
                    OptionSection::content('Guide', __DIR__.'/../Fixtures/markup/display-guide.php'),
                ]),
            ],
            layout: OptionScreenLayout::Tabs,
        );
    }

    private function secondGroup(): FieldGroup
    {
        return new FieldGroup(self::SECOND_GROUP, ObjectContext::Option, [
            new TextField('fixture_second_text', StorageTarget::Meta, label: 'Second'),
        ], label: 'Fixture second group');
    }

    private function group(): FieldGroup
    {
        return new FieldGroup(self::GROUP, ObjectContext::Option, [
            new TextField('fixture_text', StorageTarget::Meta, label: 'Text'),
        ], label: 'Fixture group');
    }

    private function bootScreen(array $params = []): void
    {
        $this->registry->register($this->group());
        $this->registry->register($this->secondGroup());
        $this->signInAsAdministrator();

        (new FieldsUiProvider())->boot($this->container(
            new ArrayRequestInput(params: $params),
            [$this->tabbedScreen()],
        ));

        $this->loadAdminApi();
        $this->resetMenus();
        \do_action(Hooks::ADMIN_MENU);
    }

    /**
     * @param list<OptionScreen> $screens
     */
    private function container(ArrayRequestInput $request, array $screens): Container
    {
        $container = new Container();
        $container->set(service: $this->registry, id: FieldRegistryContract::class);
        $container->set(service: $this->reader, id: FieldReaderContract::class);
        $container->set(service: $this->writer, id: FieldWriterContract::class);
        $container->set(service: $request, id: RequestInputContract::class);
        $container->set($this->diagnostics(), id: Diagnostics::class);
        $container->set(new DeclaredOptionScreens($screens), id: OptionScreens::class);

        (new FieldsUiProvider())->register($container);

        return $container;
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

    private function resetMenus(): void
    {
        $GLOBALS['submenu'] = [];
        $GLOBALS['menu'] = [];
    }
}
