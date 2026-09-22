<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\ObjectKind;

/**
 * One panel's props: the group identity, the object it edits, the control
 * props list, the nonce field markup the caller built with wp_nonce_field(),
 * the expected-state hash the form carries, and the field-keyed error map.
 *
 * No WP_Post crosses into the props; they carry an int and a kind.
 *
 * @param list<FieldControlProps> $controls
 * @param array<string, string>   $errors   field id to message
 */
final readonly class FieldEditorProps
{
    /**
     * @param list<FieldControlProps> $controls
     * @param array<string, string>   $errors
     */
    public function __construct(
        public string $groupId,
        public ObjectKind $objectKind,
        public int $objectId,
        public array $controls,
        public string $nonceField,
        public string $expectedHash,
        public array $errors = [],
    ) {
    }
}
