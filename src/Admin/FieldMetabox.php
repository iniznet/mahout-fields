<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Contracts\FieldEditor;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Exception\GroupNotFound;
use Iniznet\Mahout\Fields\ObjectKind;

/**
 * The metabox shell: one registration and one callback per (post type, group)
 * pair. Registration is greppable -- Admin\FieldsUiProvider calls register()
 * inside the add_meta_boxes listener it attaches, never at file scope -- and
 * the callback delegates rendering to the package's FieldEditor, so the panel
 * is built once and in one way: props() reads through the field layer, render()
 * hands the controls to their markup files.
 */
final readonly class FieldMetabox
{
    public function __construct(
        private FieldEditor $editor,
        private FieldRegistry $registry,
    ) {
    }

    /**
     * One metabox, for one group on one post type. A compact group sits in
     * the sidebar -- the placement is the group declaration's, never the
     * caller's -- and no metabox is registered with the block-editor flag,
     * which would put it inside core's own panels.
     */
    public function register(string $postType, string $groupId): void
    {
        \add_meta_box(
            self::id($groupId),
            $this->title($groupId),
            $this->render(...),
            $postType,
            $this->context($groupId),
            'default',
            ['__group_id' => $groupId],
        );
    }

    /** The metabox id, stable across requests for core's metabox ordering. */
    public static function id(string $groupId): string
    {
        return 'mahout-fields-'.$groupId;
    }

    /**
     * The metabox callback. The nonce field is built here -- it is request
     * work, and the editor deliberately performs none -- and handed to the
     * panel's props. An unknown group refuses loudly; the group was
     * registered for this screen and its absence is a composition error.
     *
     * @param \WP_Post             $post the post being edited
     * @param array<string, mixed> $box  core's metabox args, carrying the group id
     */
    public function render(\WP_Post $post, array $box): void
    {
        $args = \is_array($box['args'] ?? null) ? $box['args'] : [];
        $groupId = \is_string($args['__group_id'] ?? null) ? $args['__group_id'] : '';
        $nonceField = \wp_nonce_field(Nonces::action($post->ID), Nonces::nonceField(), true, false);

        echo $this->editor->render($this->editor->props($groupId, $post->ID, ObjectKind::Post, $nonceField));
    }

    private function title(string $groupId): string
    {
        try {
            $label = $this->registry->group($groupId)->label;

            return $label ?? $groupId;
        } catch (GroupNotFound) {
            return $groupId;
        }
    }

    private function context(string $groupId): string
    {
        try {
            return $this->registry->group($groupId)->compact ? 'side' : 'normal';
        } catch (GroupNotFound) {
            return 'normal';
        }
    }
}
