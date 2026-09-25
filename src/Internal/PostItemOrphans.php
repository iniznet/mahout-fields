<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Db\Contracts\OrphanSource;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Fields\FieldLeavesTable;
use Iniznet\Mahout\Fields\ObjectKind;

/**
 * The items table's orphan source: one row per repeater item, addressed by
 * object_id. Column names and the table identifier come from the declaration
 * object only; values go through the connection's placeholders.
 *
 * The table has no tombstone state -- a delete removes the rows -- so
 * isTombstoned() is a declared false.
 *
 * @internal
 */
final readonly class PostItemOrphans implements OrphanSource
{
    public function __construct(
        private SqlConnection $connection,
        private Table $table,
    ) {
    }

    #[\Override]
    public function table(): Table
    {
        return $this->table;
    }

    #[\Override]
    public function keyForPost(int $postId): Row
    {
        return Row::of($this->table, [
            FieldLeavesTable::objectKindColumn() => ObjectKind::Post->value,
            FieldLeavesTable::objectIdColumn() => $postId,
        ]);
    }

    #[\Override]
    public function existingPosts(array $postIds): array
    {
        $ids = \array_map(intval(...), $postIds);

        if ([] === $ids) {
            return [];
        }

        $statement = \sprintf(
            'SELECT DISTINCT %s FROM %s WHERE %s IN (%s)',
            FieldLeavesTable::objectIdColumn(),
            $this->table->name->quoted(),
            FieldLeavesTable::objectIdColumn(),
            \implode(', ', \array_fill(0, \count($ids), '%d')),
        );

        $rows = $this->connection->rowsPrepared($statement, ...$ids);

        $existing = [];
        foreach ($rows as $row) {
            $existing[] = (int) $row[FieldLeavesTable::objectIdColumn()];
        }

        return \array_values(\array_unique($existing));
    }

    #[\Override]
    public function postOf(Row $row): int
    {
        return (int) $row->value(FieldLeavesTable::objectIdColumn());
    }

    #[\Override]
    public function isTombstoned(Row $row): bool
    {
        return false;
    }
}
