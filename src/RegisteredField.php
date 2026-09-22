<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * One field's resolved registration: the declaration, the group it belongs to
 * and the storage target it is bound to.
 *
 * The target is the RESOLVED one -- what mahout/fields/storage_target left in
 * place -- and it is authoritative for every later read and write. The field
 * object stays immutable; the resolution is registry state.
 */
final readonly class RegisteredField
{
    public function __construct(
        public Field $field,
        public FieldGroup $group,
        public StorageTarget $storage,
    ) {
    }
}
