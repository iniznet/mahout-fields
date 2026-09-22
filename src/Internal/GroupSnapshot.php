<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Field;
use Iniznet\Mahout\Fields\FieldGroup;
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
     * @return list<array{field: string, items: list<string|int|float|bool>}|array{field: string, value: string|int|float|bool}>
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
     * @return array{field: string, items: list<string|int|float|bool>}|null
     */
    private function itemRow(RepeaterField $field, ObjectRef $object): ?array
    {
        $items = [];

        foreach ($this->table->readItems($field, $object) as $item) {
            if (null !== $item['value']) {
                $items[] = $item['value'];
            }
        }

        return [] === $items ? null : ['field' => $field->id, 'items' => $items];
    }
}
