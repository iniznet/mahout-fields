<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Exception\MigrationIrreversible;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;

/**
 * The address grammar's migration: the one-row-per-item items table becomes
 * the one-row-per-leaf leaves table.
 *
 * The copy is chunked with an explicit LIMIT and idempotent — every chunk
 * upserts, so an interrupted run re-runs from the top without duplicating a
 * row — and the old table drops only after the last chunk landed. A flat old
 * row maps to the address grammar trivially: the position becomes the
 * relative address, the member is empty, and the value lands in the column
 * its field type always used.
 *
 * The reversal is irreversible by declaration: a nested address cannot map
 * back to one row per item, and the envelope format the old Meta path stored
 * no longer exists to rebuild.
 */
final readonly class FlattenFieldItemsToAddresses implements Migration
{
    private const int CHUNK = 500;

    public function __construct(
        private SqlConnection $connection,
        private TableGateway $gateway,
        private DdlEmitter $emitter,
    ) {
    }

    public function name(): string
    {
        return 'mahout/fields/items_to_leaves';
    }

    public function up(): void
    {
        $leaves = FieldLeavesTable::table($this->connection->prefix(), $this->connection->charsetCollate());
        $legacy = $this->legacyName();

        if (null === $legacy) {
            return;
        }

        $offset = 0;

        do {
            $rows = $this->legacyRows($legacy, $offset, self::CHUNK);
            $offset += count($rows);

            foreach ($rows as $row) {
                $this->gateway->upsert(Row::of($leaves, $this->leafRow($leaves, $row)));
            }
        } while ([] !== $rows);

        $this->connection->execute($this->emitter->drop($legacy));
    }

    public function down(): void
    {
        throw MigrationIrreversible::because($this->name(), $this->irreversibleReason());
    }

    /** @return string this migration never reverses */
    public function irreversibleReason(): string
    {
        return 'a nested leaf address cannot map back to one row per item';
    }

    /**
     * The legacy table's name, or null when this install never created one.
     * The existence probe is a schema query on a migration path — never a
     * request path — and it names the table by a bound placeholder.
     */
    private function legacyName(): ?Identifier
    {
        $name = Identifier::prefixed($this->connection->prefix(), 'mahout_field_items');

        $rows = $this->connection->rowsPrepared(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
            $name->value,
        );

        return [] === $rows ? null : $name;
    }

    /**
     * One bounded chunk of the legacy rows, in primary-key order. The offset
     * walk is safe to re-run from the top because every chunk upserts.
     *
     * @return list<array<string, string|int|null>>
     */
    private function legacyRows(Identifier $legacy, int $offset, int $limit): array
    {
        $statement = 'SELECT object_kind, object_id, field_id, position, value_text, value_int'
            .' FROM '.$legacy->quoted()
            .' ORDER BY object_id, field_id, position'
            .' LIMIT '.$limit.' OFFSET '.$offset;

        $rows = $this->connection->rows($statement);

        /* @var list<array<string, string|int|null>> $rows */
        return $rows;
    }

    /**
     * @param array<string, string|int|null> $row the legacy row's values
     *
     * @return array<string, string|int|null>
     */
    private function leafRow(Table $leaves, array $row): array
    {
        $position = (int) ($row['position'] ?? 0);
        $text = $row['value_text'] ?? null;
        $int = $row['value_int'] ?? null;

        return [
            FieldLeavesTable::objectKindColumn() => (int) ($row['object_kind'] ?? 0),
            FieldLeavesTable::objectIdColumn() => (int) ($row['object_id'] ?? 0),
            FieldLeavesTable::groupIdColumn() => (string) ($row['field_id'] ?? ''),
            FieldLeavesTable::addressColumn() => (string) $position,
            FieldLeavesTable::memberColumn() => '',
            FieldLeavesTable::textColumn() => null === $text ? null : (string) $text,
            FieldLeavesTable::intColumn() => null === $int ? null : (int) $int,
            FieldLeavesTable::decColumn() => null,
            FieldLeavesTable::dateColumn() => null,
        ];
    }
}
