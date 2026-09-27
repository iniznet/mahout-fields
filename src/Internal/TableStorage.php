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
        private ValueRowStore $rows = new ValueRowStore(),
        private LeafRowStore $leafRows = new LeafRowStore(),
    ) {
    }

    /**
     * One statement for a whole page of objects: every scalar row the given
     * objects own, filed into the store so the per-field reads that follow are
     * memory lookups. The ceiling is the caller's proven maximum rows per object
     * times the object count, so the statement is bounded by the page rather than
     * by how many rows the page turns out to own.
     *
     * @param list<int> $objectIds
     */
    public function prime(ObjectKind $kind, array $objectIds, int $ceiling): void
    {
        if ([] === $objectIds) {
            return;
        }

        $rows = $this->gateway->select(GatewayQuery::among(
            Row::of($this->values, [FieldValuesTable::objectKindColumn() => $kind->value]),
            $this->values->column(FieldValuesTable::objectIdColumn()),
            $objectIds,
            $ceiling,
        ));

        $byObject = [];

        foreach ($rows as $row) {
            $byObject[(int) $row->value(FieldValuesTable::objectIdColumn())][] = $row;
        }

        foreach ($objectIds as $id) {
            $this->rows->file(ValueRowStore::key($kind, $id), $byObject[$id] ?? []);
        }
    }

    public function primed(ObjectKind $kind, int $objectId): bool
    {
        return $this->rows->primed(ValueRowStore::key($kind, $objectId));
    }

    /**
     * One statement for a whole page of objects, for one repeater group: every leaf
     * row those objects own for that group, filed so the reads that follow are memory
     * lookups.
     *
     * The ceiling is the caller's proven maximum leaves per object for this group - the
     * declared item bound times the declared member count, recursively - which is why
     * only a repeater that declares one is ever primed. A bound the write path enforces
     * is a fact; a guess at one is a LIMIT that truncates silently, and a page that
     * lost an item to its own prime is worse than a page that spent a statement.
     *
     * @param list<int> $objectIds
     */
    public function primeLeaves(ObjectKind $kind, array $objectIds, string $groupId, int $ceiling): void
    {
        $pending = \array_values(\array_filter(
            $objectIds,
            fn (int $id): bool => !$this->leafRows->primed(LeafRowStore::key($kind, $id, $groupId)),
        ));

        if ([] === $pending || 1 > $ceiling) {
            return;
        }

        $objectIds = $pending;

        $rows = $this->gateway->select(GatewayQuery::among(
            Row::of($this->leaves, [
                FieldLeavesTable::objectKindColumn() => $kind->value,
                FieldLeavesTable::groupIdColumn() => $groupId,
            ]),
            $this->leaves->column(FieldLeavesTable::objectIdColumn()),
            $objectIds,
            $ceiling + 1,
        ));

        // One row past the bound came back, so the bound the declaration states is not
        // the bound the data obeys - a migration that widened a group, a write that
        // bypassed the field layer. The prime declines, every object keeps the single
        // keyed read it has always had, and filing a partial group is the one outcome
        // this never produces.
        if (\count($rows) > $ceiling) {
            return;
        }

        $byObject = [];

        foreach ($rows as $row) {
            $byObject[(int) $row->value(FieldLeavesTable::objectIdColumn())][] = $row;
        }

        foreach ($objectIds as $id) {
            $this->leafRows->file(LeafRowStore::key($kind, $id, $groupId), $byObject[$id] ?? []);
        }
    }

    /**
     * @return string|int|null the raw stored value, or null when absent
     */
    public function read(Field $field, ObjectRef $object): string|int|null
    {
        $kind = $this->kind($object, $field);

        // A primed object answers from the page read; an unprimed one still costs a
        // single primary-key equality, which is the shape it has always had.
        if ($this->rows->primed(ValueRowStore::key($kind, $object->id))) {
            $row = $this->rows->row(ValueRowStore::key($kind, $object->id), $field->id);

            if (null === $row) {
                return null;
            }

            /** @var string $column FieldValuesTable::columnFor() is non-null for every scalar type */
            $column = FieldValuesTable::columnFor($field->type()) ?? '';

            return $row->has($column) ? $row->value($column) : null;
        }

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
                // The leaves table owns the leaf's column, and the leaf
                // query reads through the same map: a write and its
                // member-qualified query can never disagree about where a
                // type lives.
                $column = FieldLeavesTable::columnFor($leaf['field']->type());

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

        // A primed group answers from the page read; an unprimed one - a repeater with
        // no declared bound, or an object this page never primed - still costs the
        // single primary-key equality it has always cost. One lookup carries both
        // facts: a filed group is a list, possibly empty, and an unfiled one is null.
        $rows = $this->leafRows->rows(LeafRowStore::key($kind, $object->id, $field->id))
            ?? $this->gateway->select(GatewayQuery::keyed(Row::of($this->leaves, [
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
