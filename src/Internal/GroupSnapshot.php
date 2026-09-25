<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Field;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\LeafAddress;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;

/**
 * The canonical row set of a group: one row per Table-bound field that holds
 * a value, in declaration order. It is what the mirror stores and what the
 * lost-update hash is computed over.
 *
 * The snapshot is read from the ADAPTERS inside the caller's transaction, so
 * the mirror always records the state the tables hold at the moment it is
 * written -- never a caller's claim about it.
 *
 * @internal
 */
final readonly class GroupSnapshot
{
    public function __construct(
        private readonly FieldRegistry $registry,
        private readonly TableStorage $table,
    ) {
    }

    /**
     * @return list<array{field: string, leaves: list<array{address: string, value: string|int|float|bool}>}|array{field: string, value: string|int|float|bool}>
     */
    public function rows(FieldGroup $group, ObjectRef $object): array
    {
        $rows = [];

        foreach ($group->fields as $field) {
            if (StorageTarget::Table !== $this->registry->resolve($field->id)->storage) {
                continue;
            }

            $row = $field instanceof RepeaterField
                ? $this->itemRow($field, $object)
                : $this->scalarRow($field, $object);

            if (null !== $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array{field: string, value: string|int|float|bool}|null
     */
    private function scalarRow(Field $field, ObjectRef $object): ?array
    {
        $raw = $this->table->read($field, $object);

        if (null === $raw) {
            return null;
        }

        $value = $field->cast($raw);

        return null === $value ? null : ['field' => $field->id, 'value' => $value];
    }

    /**
     * The repeater's leaves, relative address to stored value, in address
     * order. The mirror's row shape is the leaf set — the storage shape
     * itself — so a revision restores exactly the rows the table held.
     *
     * @return array{field: string, leaves: list<array{address: string, value: string|int|float|bool}>}|null
     */
    private function itemRow(RepeaterField $field, ObjectRef $object): ?array
    {
        $leaves = [];

        foreach ($this->table->readLeaves($field, $object) as $leaf) {
            if (null === $leaf['raw']) {
                continue;
            }

            $relative = LeafAddress::relative($leaf['address']);
            $leaves[] = ['address' => $relative, 'value' => $leaf['raw']];
        }

        return [] === $leaves ? null : ['field' => $field->id, 'leaves' => $leaves];
    }
}
