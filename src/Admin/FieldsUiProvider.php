<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Capabilities;
use Iniznet\Mahout\Fields\Contracts\ControlRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldEditor as FieldEditorContract;
use Iniznet\Mahout\Fields\Contracts\FieldReader as FieldReaderContract;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry as FieldRegistryContract;
use Iniznet\Mahout\Fields\Contracts\FieldWriter as FieldWriterContract;
use Iniznet\Mahout\Fields\Contracts\Panels;
use Iniznet\Mahout\Fields\Contracts\RequestInput as RequestInputContract;
use Iniznet\Mahout\Fields\Field;
use Iniznet\Mahout\Fields\FieldPanel;
use Iniznet\Mahout\Fields\Hooks;
use Iniznet\Mahout\Kernel\Container;
use Iniznet\Mahout\Kernel\Contracts\ServiceProvider;
use Iniznet\Mahout\Kernel\Diagnostics;

/**
 * The fields admin UI: the opt-in half of mahout-fields.
 *
 * `FieldsProvider` declares the reader, the writer and the registry and renders
 * nothing. This provider turns the host's declared panels into the screens that
 * edit them, and it is the only place in the package that calls
 * `add_meta_box()`, attaches the classic save entry, registers a REST route or
 * prints an admin notice. A host that registers it gets the whole editing
 * surface; a host that does not, or that declares no panel, gets none of it.
 * The opt-in and the no-panels case are one code path -- resolve the panels,
 * and attach nothing when there is no declaration to attach -- so there is no
 * degraded mode to configure and nothing attached to a screen nobody declared.
 *
 * ```php
 * $kernel->provider(new FieldsProvider());     // the storage core, first
 * $kernel->provider(new FieldsUiProvider());   // the panels, after it
 * ```
 *
 * `register()` runs after `FieldsProvider`'s, because the control registry and
 * the editor are built on the registry and the reader that provider binds. The
 * two seams this provider owns are `Contracts\ControlRegistry` and
 * `Contracts\FieldEditor`: it binds the package's default behind each, and a
 * host that wants another one binds its own under the same id after this
 * provider registers -- the composition root's order is the override, and it is
 * greppable there. A host that wants another *control* uses
 * `mahout/fields/editor_controls`, which the registry applies at construction,
 * because the control map is one filtered value and not a binding.
 *
 * The one host binding this provider reads is `Contracts\Panels`: every
 * metabox, save entry, read binding and notice is derived from that
 * declaration and from nothing else. A host with panels must also bind
 * `Contracts\RequestInput`, the adapter over its own request boundary -- the
 * package never reads a superglobal, so the foreign-form guard is decided by
 * the host's reader. Both are resolved in `boot()`, where a missing binding is
 * a composition error and fails loudly through the container.
 */
final class FieldsUiProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $controls = new FieldTypeRegistry();
        $container->set(service: $controls, id: ControlRegistry::class);

        $editor = new FieldEditor(
            $controls,
            $container->get(FieldRegistryContract::class),
            $container->get(FieldReaderContract::class),
        );
        $container->set(service: $editor, id: FieldEditorContract::class);
    }

    public function boot(Container $container): void
    {
        if (!$container->has(Panels::class)) {
            return;
        }

        $panels = $container->get(Panels::class);

        if ($panels->isEmpty()) {
            return;
        }

        $notices = new WriteFailureNotice();

        $this->attachMetaboxes($container, $panels);
        $this->attachSave($container, $notices);
        $this->attachRest($container, $panels);
        $this->attachNotice($notices);
    }

    /**
     * One metabox per declared (post type, group) pair, on the screen that
     * pair names. The capability is decided before a panel is looked up: a
     * panel the user cannot edit the post for is an information leak, so the
     * metabox is not registered at all rather than rendered without values.
     */
    private function attachMetaboxes(Container $container, Panels $panels): void
    {
        $metabox = new FieldMetabox(
            $container->get(FieldEditorContract::class),
            $container->get(FieldRegistryContract::class),
        );

        \add_action(
            Hooks::ADD_META_BOXES,
            static function (string $postType, \WP_Post $post) use ($panels, $metabox): void {
                if (!\current_user_can(Capabilities::EditPost->value, $post->ID)) {
                    return;
                }

                foreach ($panels->forPostType($postType) as $panel) {
                    $metabox->register($panel->postType, $panel->group->id);
                }
            },
            priority: 10,
            accepted_args: 2,
        );
    }

    /**
     * The classic save path, at priority 10 with accepted_args 3. The guard
     * order -- autosave, revision, foreign form, lock, capability, nonce,
     * shape, sanitise, store, record -- is the handler's, and this attachment
     * changes nothing in it.
     */
    private function attachSave(Container $container, WriteFailureNotice $notices): void
    {
        $handler = new FieldSaveHandler(
            request: $container->get(RequestInputContract::class),
            writer: $container->get(FieldWriterContract::class),
            registry: $container->get(FieldRegistryContract::class),
            diagnostics: $container->get(Diagnostics::class),
            notices: $notices,
        );

        \add_action(Hooks::SAVE_POST, $handler->handle(...), priority: 10, accepted_args: 3);
    }

    /**
     * The field route's write path and the read bindings that carry Table
     * storage into the editor's REST representation. The route is registered
     * once; the reads are registered per panel, because a field reaches the
     * representation of the post type that declares it and of no other.
     */
    private function attachRest(Container $container, Panels $panels): void
    {
        $route = new FieldRestRoute(
            $container->get(FieldRegistryContract::class),
            $container->get(FieldWriterContract::class),
            $container->get(FieldReaderContract::class),
            $container->get(Diagnostics::class),
        );

        \add_action(
            Hooks::REST_API_INIT,
            static function () use ($panels, $route): void {
                $route->register();

                foreach ($panels as $panel) {
                    self::attachReads($route, $panel);
                }
            },
            priority: 10,
            accepted_args: 0,
        );
    }

    /**
     * The write-failure notice, at priority 20: after the screen's own
     * messages, because it reports a refusal of the save the user just
     * attempted. The same store the handler queues into is the store the
     * notice takes from, so one value crosses the seam and it is the host's
     * transient, never a static.
     */
    private function attachNotice(WriteFailureNotice $notices): void
    {
        $renderer = new WriteFailureNoticeRenderer($notices);

        \add_action(
            Hooks::ADMIN_NOTICES,
            static function () use ($renderer): void {
                $renderer->render();
            },
            priority: 20,
            accepted_args: 0,
        );
    }

    /** The one read binding for every field the panel's group declares. */
    private static function attachReads(FieldRestRoute $route, FieldPanel $panel): void
    {
        $route->registerReads(
            $panel->postType,
            ...\array_map(static fn (Field $field): string => $field->id, $panel->group->fields),
        );
    }
}
