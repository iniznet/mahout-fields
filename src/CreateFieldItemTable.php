<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\DdlEmitter;

/**
 * The repeater items table's migration: up() creates it, down() drops it.
 */
final readonly class CreateFieldItemTable implements Migration
{
    public function __construct(
        private SqlConnection $connection,
        private DdlEmitter $emitter,
    ) {
    }

    public function name(): string
    {
        return 'mahout/fields/item_table';
    }

    public function up(): void
    {
        $this->connection->execute($this->emitter->create(FieldItemsTable::table(
            $this->connection->prefix(),
            $this->connection->charsetCollate(),
        )));
    }

    public function down(): void
    {
        $this->connection->execute($this->emitter->drop(FieldItemsTable::table(
            $this->connection->prefix(),
            $this->connection->charsetCollate(),
        )->name));
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }
}
