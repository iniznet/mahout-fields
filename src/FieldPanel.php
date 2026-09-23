<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidPanelDeclaration;

/**
 * One declared field panel: a field group bound to the post type whose edit
 * screen renders it. The group carries no post type, so the pairing is the
 * declaration's own fact, and one group may serve several post types because
 * core registers one metabox per pair.
 *
 * A panel is a value: it holds no collaborator and resolves none. The host
 * collects these into its `Contracts\Panels` implementation, and every admin
 * surface the field layer needs derives from that collection.
 */
final readonly class FieldPanel
{
    public function __construct(
        public string $postType,
        public FieldGroup $group,
    ) {
        if ('' === $postType) {
            throw InvalidPanelDeclaration::emptyPostType($group->id);
        }
    }
}
