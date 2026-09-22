<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Field;
use Iniznet\Mahout\Fields\FieldItemsTable;
use Iniznet\Mahout\Fields\FieldValuesTable;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;

/**
 * The Table storage adapter: the generic value table and the repeater items
 * table, reached only through mahout-db's typed gateway.
 *
 * Every write carries ALL of the value columns, so an upsert replaces the
 * whole row and a field whose type changed never leaves a stale value behind
 * in a sibling column. A multi-row write -- a repeater's items -- is one
 * transaction: the keyed delete and the positioned inserts commit together or
 * not at all.
 *
 * The adapter issues no SQL of its own and never names a table or column as a
 * statement string; the identifiers are read back from the declared Table
 * objects by the gateway.
 *
 * @internal
 */
final readonly class TableStorage
{
    public function __construct(
        private TableGateway $gateway,
        private Table $values,
        private Table $items,
    ) {
    }

    /**
     * @return string|int|null the raw stored value, or null when absent
     */
    public function read(Field $field, ObjectRef $object): string|int|null
    {
        $kind = $this->kind($object, $field);
        $rows = $this->gateway->select(GatewayQuery::keyed(Row::of($this->values, [
            FieldValuesTable::objectKindColumn() => $kind->value,
            FieldValuesTable::objectIdColumn() => $object->id,
            FieldValuesTable::fieldIdColumn() => $field->id,
        ])));

        $row = $rows[0] ?? null;

        if (null === $row) {
            return null;
        }

        /** @var string $column FieldValuesTable::columnFor() is non-null for every scalar type */
        $column = FieldValuesTable::columnFor($field->type()) ?? '';

        $raw = $row->has($column) ? $row->value($column) : null;

        return $raw;
    }

    public function write(Field $field, ObjectRef $object, string|int|float|bool $value): void
    {
        $kind = $this->kind($object, $field);
        $column = FieldValuesTable::columnFor($field->type());

        if (null === $column) {
            throw InvalidFieldWrite::jsonIntoItemsTable($field->id);
        }

        $this->gateway->upsert(Row::of($this->values, [
            FieldValuesTable::objectKindColumn() => $kind->value,
            FieldValuesTable::objectIdColumn() => $object->id,
            FieldValuesTable::fieldIdColumn() => $field->id,
            FieldValuesTable::textColumn() => null,
            FieldValuesTable::intColumn() => null,
            FieldValuesTable::decimalColumn() => null,
            FieldValuesTable::dateColumn() => null,
            $column => $this->stored($value),
        ]));
    }

    /**
     * One row per item at an explicit position, replacing whatever the field
     * held, in one transaction.
     *
     * @param list<string|int|float|bool> $items the raw item values, sanitised once here
     */
    public function writeItems(RepeaterField $field, ObjectRef $object, array $items): void
    {
        $kind = $this->kind($object, $field);

        $this->gateway->transactional(function () use ($kind, $object, $field, $items): void {
            $this->gateway->delete(GatewayQuery::keyed(Row::of($this->items, [
                FieldItemsTable::objectKindColumn() => $kind->value,
                FieldItemsTable::objectIdColumn() => $object->id,
                FieldItemsTable::fieldIdColumn() => $field->id,
            ])));

            $position = 0;
            foreach ($items as $item) {
                $this->gateway->insert(Row::of($this->items, [
                    FieldItemsTable::objectKindColumn() => $kind->value,
                    FieldItemsTable::objectIdColumn() => $object->id,
                    FieldItemsTable::fieldIdColumn() => $field->id,
                    FieldItemsTable::positionColumn() => $position++,
                    ...$this->itemColumns($field, $item),
                ]));
            }
        });
    }

    /**
     * The stored items in declared order. The gateway's select carries no
     * ORDER BY, so the declared position -- the row's own key -- orders them
     * here; the row count is the item count, which is what keeps the read
     * bounded.
     *
     * @return list<array{position: int, value: string|int|float|bool|null}>
     */
    public function readItems(RepeaterField $field, ObjectRef $object): array
    {
        $kind = $this->kind($object, $field);
        $rows = $this->gateway->select(GatewayQuery::keyed(Row::of($this->items, [
            FieldItemsTable::objectKindColumn() => $kind->value,
            FieldItemsTable::objectIdColumn() => $object->id,
            FieldItemsTable::fieldIdColumn() => $field->id,
        ])));

        $items = [];
        foreach ($rows as $row) {
            $position = (int) $row->value(FieldItemsTable::positionColumn());
            /** @var string $column the item field's column is always text or int on this table */
            $column = FieldValuesTable::itemColumnFor($field->item->type());
            $raw = $row->has($column) ? $row->value($column) : null;

            $items[] = [
                'position' => $position,
                'value' => $field->item->cast($raw),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return $items;
    }

    public function delete(Field $field, ObjectRef $object): void
    {
        $kind = $this->kind($object, $field);

        $this->gateway->delete(GatewayQuery::keyed(Row::of($this->values, [
            FieldValuesTable::objectKindColumn() => $kind->value,
            FieldValuesTable::objectIdColumn() => $object->id,
            FieldValuesTable::fieldIdColumn() => $field->id,
        ])));
    }

    public function deleteItems(RepeaterField $field, ObjectRef $object): void
    {
        $kind = $this->kind($object, $field);

        $this->gateway->delete(GatewayQuery::keyed(Row::of($this->items, [
            FieldItemsTable::objectKindColumn() => $kind->value,
            FieldItemsTable::objectIdColumn() => $object->id,
            FieldItemsTable::fieldIdColumn() => $field->id,
        ])));
    }

    /**
     * The one sanitisation of an item, into its declared column's shape.
     *
     * @return array<string, string|int|null>
     */
    private function itemColumns(RepeaterField $field, string|int|float|bool $item): array
    {
        $sanitised = $field->item->sanitise($item);
        $column = FieldValuesTable::itemColumnFor($field->item->type());

        $row = [
            FieldItemsTable::textColumn() => null,
            FieldItemsTable::intColumn() => null,
        ];

        if (null !== $sanitised) {
            $row[$column] = $this->stored($sanitised);
        }

        return $row;
    }

    private function stored(string|int|float|bool $value): string|int
    {
        return \is_bool($value) ? ($value ? 1 : 0) : (\is_float($value) ? (string) $value : $value);
    }

    private function kind(ObjectRef $object, Field $field): ObjectKind
    {
        $kind = ObjectKind::fromContext($object->context);

        if (null === $kind) {
            throw InvalidFieldWrite::optionRow($field->id);
        }

        return $kind;
    }
}
