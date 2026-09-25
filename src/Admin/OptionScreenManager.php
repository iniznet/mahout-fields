<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Contracts\FieldEditor;
use Iniznet\Mahout\Fields\Contracts\FieldWriter;
use Iniznet\Mahout\Fields\Contracts\OptionScreens;
use Iniznet\Mahout\Fields\Contracts\RequestInput;
use Iniznet\Mahout\Fields\Exception\AuthorizationDenied;
use Iniznet\Mahout\Fields\Exception\MahoutException;
use Iniznet\Mahout\Fields\Exception\NonceFailed;
use Iniznet\Mahout\Fields\Hooks;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\OptionScreen;
use Iniznet\Mahout\Fields\OptionTab;
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The option screens' admin surface: one settings page per declared screen,
 * rendered and saved through the same pipeline the metaboxes use.
 *
 * This is the only class in the package that registers a settings page, and
 * it reaches the page only through Admin\FieldsUiProvider's one `admin_menu`
 * listener: a screen the current user's capability refuses is not registered
 * at all -- never rendered without values, never reachable as an empty page.
 * Rendering walks each field section's group through the same
 * `Contracts\FieldEditor` and control registry the metaboxes use, so an
 * option field is rendered in one way and in one way only; a content section
 * is the declaring feature's own markup file, rendered as it ships it. A
 * screen whose active tab carries no field section renders no form at all --
 * a documentation or guide page -- and gets no save entry.
 *
 * The active tab is the request's `tab` parameter through the request
 * adapter -- URL state, never a superglobal read here -- and the form
 * carries it back as a hidden field, so the save writes exactly the tab the
 * page rendered. An unknown tab parameter renders the first tab.
 *
 * The save entry runs on the page's own load hook, at priority 5 -- before
 * the render callback core attached, so the page renders the stored state
 * after the save and never the state it was submitted with. The guard order
 * is the save lifecycle's, adapted to a surface that addresses no object:
 *
 * 1 capability -- the screen's own, `AuthorizationDenied`. 2 the submitted
 * form -- the request adapter's `has()` over the panel's nonce field, silent,
 * because the package reads no superglobal and the method fact is the host
 * adapter's. 3 the active tab's presence in the submission, silent -- a
 * stale form for another tab writes nothing. 4 nonce -- the screen's own
 * action, verified once before any group is written, `NonceFailed`, one
 * warning, never `wp_die()`: core's check_admin_referer() verifies the same
 * action but terminates the request, and the save lifecycle refuses loudly
 * and notifies instead. 5 field shape -- inside writeGroup(), which refuses
 * an id the group does not declare before any value is sanitised. 6 sanitise,
 * inside writeGroup(), once, before the store opens. 7 store -- writeGroup()
 * with `ObjectRef::option()`, once per field section in the order the
 * sections render. 8 record -- a refusal records once through the same
 * `WriteFailureNotice` store the metabox path queues into, under the group it
 * refused, and the page renders without that write.
 *
 * No wp_die(), no setcookie, no retry, no substitute: a failed write notifies
 * and the screen renders the stored values.
 */
final readonly class OptionScreenManager
{
    /** The request parameter naming the active tab, echoed back by the form's hidden field. */
    public const string TAB_PARAM = 'tab';

    public function __construct(
        private OptionScreens $screens,
        private FieldEditor $editor,
        private FieldWriter $writer,
        private RequestInput $request,
        private Diagnostics $diagnostics,
        private WriteFailureNotice $notices = new WriteFailureNotice(),
    ) {
    }

    /**
     * One submenu page per declared screen, on the parent each screen names.
     * The capability is decided before the page is registered: a screen the
     * user cannot reach is not registered at all, so its save entry and its
     * notice do not exist either. A screen whose tabs carry no field section
     * registers no save entry and no notice either: nothing can be submitted
     * to it, so nothing can fail.
     */
    public function register(): void
    {
        $renderer = new WriteFailureNoticeRenderer($this->notices);

        foreach ($this->screens as $screen) {
            if (!\current_user_can($screen->capability)) {
                continue;
            }

            // The placement is the declaration's own fact: a top-level menu
            // registers itself, a submenu screen sits on the parent it names.
            $pageHook = $screen->topLevel
                ? \add_menu_page(
                    $screen->pageTitle,
                    $screen->menuTitle,
                    $screen->capability,
                    $screen->pageSlug,
                    function () use ($screen): void {
                        $this->render($screen);
                    },
                    $screen->menuIcon,
                )
                : \add_submenu_page(
                    $screen->menuParent,
                    $screen->pageTitle,
                    $screen->menuTitle,
                    $screen->capability,
                    $screen->pageSlug,
                    function () use ($screen): void {
                        $this->render($screen);
                    },
                );

            if (!\is_string($pageHook) || '' === $pageHook) {
                continue;
            }

            if ($screen->hasFields()) {
                \add_action(
                    Hooks::screenLoad($pageHook),
                    function () use ($screen): void {
                        $this->save($screen);
                    },
                    priority: 5,
                    accepted_args: 0,
                );

                \add_action(
                    Hooks::ADMIN_NOTICES,
                    static function () use ($screen, $renderer): void {
                        $renderer->renderForScreen($screen->pageSlug);
                    },
                    priority: 20,
                    accepted_args: 0,
                );
            }
        }
    }

    /**
     * The page's render callback. Every tab's sections render, the inactive
     * tab's panel hidden: the page is one load, and the shipped script swaps
     * the visible panel without a request. The nonce field is built here --
     * it is request work, and the editor deliberately performs none -- and
     * the page's first field section's props carry it, so the form holds
     * exactly one nonce however many field sections the page renders.
     */
    private function render(OptionScreen $screen): void
    {
        $active = $this->activeTab($screen);

        $panels = [];

        foreach ($screen->tabs as $tab) {
            // Each fields panel is its own form: a tab that carries no fields
            // renders no form at all, and a submission posts exactly the
            // panel it came from. The nonce is per screen action, so every
            // fields panel verifies against the same save.
            $panelNonce = '';
            $sections = [];

            foreach ($tab->sections as $section) {
                $markup = '';

                if (null !== $section->group) {
                    if ('' === $panelNonce) {
                        $panelNonce = \wp_nonce_field(Nonces::screenAction($screen->pageSlug), Nonces::nonceField(), true, false);
                    }

                    $markup = $this->editor->render($this->editor->propsForGroup($section->group->id, $panelNonce));
                } else {
                    $markup = self::contentMarkup($section->markupPath ?? '');
                }

                $sections[] = ['title' => $section->title, 'markup' => $markup];
            }

            $panels[] = [
                'label' => $tab->label,
                'hidden' => $tab->label !== $active->label,
                'hasFields' => '' !== $panelNonce,
                'sections' => $sections,
            ];
        }

        \ob_start();
        $view = [
            'screen' => $screen,
            'active' => $active,
            'panels' => $panels,
            'action' => \admin_url($screen->menuParent.'?page='.rawurlencode($screen->pageSlug)),
            'tabParam' => self::TAB_PARAM,
        ];
        require __DIR__.'/Control/markup/option-page.php';

        echo (string) \ob_get_clean();
    }

    /**
     * A content section's markup, rendered with nothing else in scope: the
     * file is the declaring feature's own static markup, escaped at its own
     * outputs the way every component's markup is.
     */
    private static function contentMarkup(string $markupPath): string
    {
        return (string) (static function () use ($markupPath): string {
            \ob_start();
            require $markupPath;

            return (string) \ob_get_clean();
        })();
    }

    /**
     * The request's active tab: the `tab` parameter's label when it names a
     * declared tab, the first tab otherwise -- navigation state, not stored
     * data, so an unknown parameter names the page's own first tab.
     */
    private function activeTab(OptionScreen $screen): OptionTab
    {
        $requested = $this->request->param(self::TAB_PARAM);

        if (null !== $requested) {
            foreach ($screen->tabs as $tab) {
                if ($tab->label === $requested) {
                    return $tab;
                }
            }
        }

        return $screen->tabs[0];
    }

    /**
     * The page's save entry, in the declared guard order. A refusal records
     * once and queues the screen's notice; a save that was refused wrote
     * nothing, and nothing is rolled back because nothing is partial.
     */
    private function save(OptionScreen $screen): void
    {
        if (!\current_user_can($screen->capability)) {
            $this->refuse($screen, AuthorizationDenied::forObject(ObjectRef::option()->id), $screen->pageSlug);

            return;
        }

        if (!$this->request->has(Nonces::nonceField())) {
            return;
        }

        $tab = $this->activeTab($screen);
        $submitted = [];

        foreach ($tab->sections as $section) {
            if (null !== $section->group && isset($this->request->groups()[$section->group->id])) {
                $submitted[] = $section->group;
            }
        }

        if ([] === $submitted) {
            return;
        }

        try {
            if (1 !== \wp_verify_nonce(
                $this->request->string(Nonces::nonceField()) ?? '',
                Nonces::screenAction($screen->pageSlug),
            )) {
                throw NonceFailed::forObject(ObjectRef::option()->id);
            }
        } catch (MahoutException $refusal) {
            $this->refuse($screen, $refusal, $submitted[0]->id);

            return;
        }

        foreach ($submitted as $group) {
            try {
                $this->writer->writeGroup(
                    $group->id,
                    ObjectRef::option(),
                    $this->request->groups()[$group->id],
                    $this->request->hashes()[$group->id] ?? '',
                );
            } catch (MahoutException $refusal) {
                $this->refuse($screen, $refusal, $group->id);
            }
        }
    }

    /**
     * Guard 8: the refusal records once, with the diagnostics reference the
     * classic path records under, and the screen's notice queues it.
     */
    private function refuse(OptionScreen $screen, \Throwable $refusal, string $groupId): void
    {
        $this->notices->queueForScreen($screen->pageSlug, SaveRefusal::record(
            $refusal,
            $groupId,
            ObjectRef::option()->id,
            $this->diagnostics,
        ));
    }
}
