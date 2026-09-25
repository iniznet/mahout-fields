<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Field;
use Iniznet\Mahout\Fields\FieldLeavesTable;
use Iniznet\Mahout\Fields\FieldValuesTable;
use Iniznet\Mahout\Fields\LeafAddress;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;

/**
 * The Table storage adapter: the generic value table and the repeater leaves
 * table, reached only through mahout-db's typed gateway.
 *
 * Every write carries ALL of the value columns, so an upsert replaces the
 * whole row and a field whose type changed never leaves a stale value behind
 * in a sibling column. A repeater's leaves are one transaction: the keyed
 * delete over the group prefix and the addressed inserts commit together or
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
        private Table $leaves,
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
     * One row per leaf at its address, replacing whatever the repeater held,
     * in one transaction. The leaves arrive already sanitised — the writer
     * sanitises exactly once, for both targets — so the adapter
     * canonicalises and stores, and never sanitises. An empty list is the
     * repeater's removal: the keyed delete lands, nothing is inserted.
     *
     * @param list<array{address: string, relative: string, member: string, field: Field, value: string|int|float|bool}> $leaves
     */
    public function writeLeaves(RepeaterField $field, ObjectRef $object, array $leaves): void
    {
        $kind = $this->kind($object, $field);

        $this->gateway->transactional(function () use ($kind, $object, $field, $leaves): void {
            $this->gateway->delete(GatewayQuery::keyed(Row::of($this->leaves, [
                FieldLeavesTable::objectKindColumn() => $kind->value,
                FieldLeavesTable::objectIdColumn() => $object->id,
                FieldLeavesTable::groupIdColumn() => $field->id,
            ])));

            foreach ($leaves as $leaf) {
                $column = FieldValuesTable::columnFor($leaf['field']->type());

                if (null === $column) {
                    throw InvalidFieldWrite::jsonIntoItemsTable($field->id);
                }

                $this->gateway->insert(Row::of($this->leaves, [
                    FieldLeavesTable::objectKindColumn() => $kind->value,
                    FieldLeavesTable::objectIdColumn() => $object->id,
                    FieldLeavesTable::groupIdColumn() => $field->id,
                    FieldLeavesTable::addressColumn() => $leaf['relative'],
                    FieldLeavesTable::memberColumn() => $leaf['member'],
                    FieldLeavesTable::textColumn() => null,
                    FieldLeavesTable::intColumn() => null,
                    FieldLeavesTable::decColumn() => null,
                    FieldLeavesTable::dateColumn() => null,
                    $column => $this->stored($leaf['value']),
                ]));
            }
        });
    }

    /**
     * The stored leaves of one repeater, in address order. The gateway's
     * select carries no ORDER BY, so the address — the row's own key —
     * orders them here; the row count is the leaf count, which is what keeps
     * the read bounded.
     *
     * @return list<array{address: string, member: string, raw: string|int|null}>
     */
    public function readLeaves(RepeaterField $field, ObjectRef $object): array
    {
        $kind = $this->kind($object, $field);
        $rows = $this->gateway->select(GatewayQuery::keyed(Row::of($this->leaves, [
            FieldLeavesTable::objectKindColumn() => $kind->value,
            FieldLeavesTable::objectIdColumn() => $object->id,
            FieldLeavesTable::groupIdColumn() => $field->id,
        ])));

        $leaves = [];

        foreach ($rows as $row) {
            $leaves[] = [
                'address' => $field->id.'.'.(string) $row->value(FieldLeavesTable::addressColumn()),
                'member' => (string) $row->value(FieldLeavesTable::memberColumn()),
                'raw' => $this->rawValue($row),
            ];
        }

        usort($leaves, static fn (array $a, array $b): int => LeafAddress::compare($a['address'], $b['address']));

        return $leaves;
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

    public function deleteLeaves(RepeaterField $field, ObjectRef $object): void
    {
        $kind = $this->kind($object, $field);

        $this->gateway->delete(GatewayQuery::keyed(Row::of($this->leaves, [
            FieldLeavesTable::objectKindColumn() => $kind->value,
            FieldLeavesTable::objectIdColumn() => $object->id,
            FieldLeavesTable::groupIdColumn() => $field->id,
        ])));
    }

    /** The one non-null value column of a leaf row; every leaf stores exactly one. */
    private function rawValue(Row $row): string|int|null
    {
        foreach ([FieldLeavesTable::textColumn(), FieldLeavesTable::intColumn(), FieldLeavesTable::decColumn(), FieldLeavesTable::dateColumn()] as $column) {
            $raw = $row->has($column) ? $row->value($column) : null;

            if (null !== $raw) {
                return $raw;
            }
        }

        return null;
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
