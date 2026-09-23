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
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The option screens' admin surface: one settings page per declared screen,
 * rendered and saved through the same pipeline the metaboxes use.
 *
 * This is the only class in the package that registers a settings page, and it
 * reaches the page only through Admin\FieldsUiProvider's one \`admin_menu\`
 * listener: a screen the current user's capability refuses is not registered
 * at all — never rendered without values, never reachable as an empty page.
 * Rendering walks the group's fields through the same `Contracts\FieldEditor`
 * and control registry the metaboxes use, so an option field is rendered in
 * one way and in one way only.
 *
 * The save entry runs on the page's own load hook, at priority 5 — before the
 * render callback core attached, so the page renders the stored state after
 * the save and never the state it was submitted with. The guard order is the
 * save lifecycle's, adapted to a surface that addresses no object:
 *
 * 1 capability — the screen's own, `AuthorizationDenied`. 2 the submitted
 * form — the request adapter's `has()` over the panel's nonce field, silent,
 * because the package reads no superglobal and the method fact is the host
 * adapter's. 3 the group's presence in the submission, silent — a stale form
 * for another screen writes nothing. 4 nonce — the screen's own action,
 * `NonceFailed`, one warning, never `wp_die()`: core's
 * check_admin_referer() verifies the same action but terminates the request,
 * and the save lifecycle refuses loudly and notifies instead. 5 field shape —
 * inside writeGroup(), which refuses an id the group does not declare before
 * any value is sanitised. 6 sanitise, inside writeGroup(), once, before the
 * store opens. 7 store — writeGroup() with `ObjectRef::option()`. 8 record —
 * the refusal records once through the same `WriteFailureNotice` store the
 * metabox path queues into, and the page renders without the write.
 *
 * No wp_die(), no setcookie, no retry, no substitute: a failed write notifies
 * and the screen renders the stored values.
 */
final readonly class OptionScreenManager
{
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
     * notice do not exist either.
     */
    public function register(): void
    {
        $renderer = new WriteFailureNoticeRenderer($this->notices);

        foreach ($this->screens as $screen) {
            if (!\current_user_can($screen->capability)) {
                continue;
            }

            $pageHook = \add_submenu_page(
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

    /**
     * The page's render callback. The nonce field is built here — it is
     * request work, and the editor deliberately performs none — and the
     * panel's props carry the screen's own nonce action, so one screen's form
     * verifies against that screen's save and no other.
     */
    private function render(OptionScreen $screen): void
    {
        $panel = $this->editor->render($this->editor->propsForGroup(
            $screen->group->id,
            \wp_nonce_field(Nonces::screenAction($screen->pageSlug), Nonces::nonceField(), true, false),
        ));

        \ob_start();
        $view = [
            'screen' => $screen,
            'panel' => $panel,
            'action' => \admin_url($screen->menuParent.'?page='.rawurlencode($screen->pageSlug)),
        ];
        require __DIR__.'/Control/markup/option-page.php';

        echo (string) \ob_get_clean();
    }

    /**
     * The page's save entry, in the declared guard order. A refusal records
     * once and queues the screen's notice; a save that was refused wrote
     * nothing, and nothing is rolled back because nothing is partial.
     */
    private function save(OptionScreen $screen): void
    {
        $groupId = $screen->group->id;

        if (!\current_user_can($screen->capability)) {
            $this->refuse($screen, AuthorizationDenied::forObject(ObjectRef::option()->id));

            return;
        }

        if (!$this->request->has(Nonces::nonceField())) {
            return;
        }

        $values = $this->request->groups()[$groupId] ?? null;

        if (null === $values) {
            return;
        }

        try {
            if (1 !== \wp_verify_nonce(
                $this->request->string(Nonces::nonceField()) ?? '',
                Nonces::screenAction($screen->pageSlug),
            )) {
                throw NonceFailed::forObject(ObjectRef::option()->id);
            }

            $this->writer->writeGroup(
                $groupId,
                ObjectRef::option(),
                $values,
                $this->request->hashes()[$groupId] ?? '',
            );
        } catch (MahoutException $refusal) {
            $this->refuse($screen, $refusal);
        }
    }

    /**
     * Guard 8: the refusal records once, with the diagnostics reference the
     * classic path records under, and the screen's notice queues it.
     */
    private function refuse(OptionScreen $screen, \Throwable $refusal): void
    {
        $this->notices->queueForScreen($screen->pageSlug, SaveRefusal::record(
            $refusal,
            $screen->group->id,
            ObjectRef::option()->id,
            $this->diagnostics,
        ));
    }
}
