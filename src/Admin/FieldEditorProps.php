<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\ObjectKind;

/**
 * One panel's props: the group identity, the object it edits, the control
 * props list, the nonce field markup the caller built with wp_nonce_field(),
 * the expected-state hash the form carries, and the field-keyed error map.
 *
 * The object kind is the value table's discriminator, and the option context
 * has none: an option is a singleton read by key and never a row, so an option
 * panel's props carry no kind and no object id. A kind and an object id cannot
 * disagree in either direction -- a row kind with no object id, or the option
 * context with one -- so mismatched props cannot exist.
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
     *
     * @throws InvalidFieldContext when the kind and the object id disagree
     */
    public function __construct(
        public string $groupId,
        public ?ObjectKind $objectKind,
        public int $objectId,
        public array $controls,
        public string $nonceField,
        public string $expectedHash,
        public array $errors = [],
    ) {
        if (null === $objectKind && 0 !== $objectId) {
            throw InvalidFieldContext::panelObject($groupId, 'option', $objectId);
        }

        if (null !== $objectKind && $objectId < 1) {
            throw InvalidFieldContext::panelObject($groupId, $objectKind->context()->value, $objectId);
        }
    }
}
