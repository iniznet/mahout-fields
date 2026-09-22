<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;

/**
 * The value table's migration. up() creates it, down() drops it; the reversal
 * is reversible in schema terms and destructive in data terms, which the
 * rollback operator sees as an ordinary reversible migration.
 *
 * DDL never shares the gateway's transaction: it commits per statement.
 */
final readonly class CreateFieldValueTable implements Migration
{
    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
    ) {
    }

    public function name(): string
    {
        return 'mahout/fields/value_table';
    }

    public function up(): void
    {
        $this->connection->execute($this->emitter->create(FieldValuesTable::table(
            $this->connection->prefix(),
            $this->connection->charsetCollate(),
        )));
    }

    public function down(): void
    {
        $this->connection->execute($this->emitter->drop(FieldValuesTable::table(
            $this->connection->prefix(),
            $this->connection->charsetCollate(),
        )->name));
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }
}
