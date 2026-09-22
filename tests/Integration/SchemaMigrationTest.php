<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Fields\CreateFieldItemTable;
use Iniznet\Mahout\Fields\CreateFieldValueTable;
use Iniznet\Mahout\Fields\Tests\TestCase;

/**
 * The two tables this package owns: up() creates them with the declared
 * columns, indexes and engine; down() removes them. The engine is InnoDB,
 * because a Table field's write is transactional and a non-transactional
 * engine would make that boundary decorative.
 *
 * @internal
 */
final class SchemaMigrationTest extends TestCase
{
    public function testUpCreatesTheValueTableWithTheDeclaredShape(): void
    {
        $this->dropTables();
        $migration = new CreateFieldValueTable($this->connection(), new DdlEmitter());

        $migration->up();

        self::assertTrue($this->tableExists($this->valuesTable));

        $columns = $this->columnNames($this->valuesTable);
        sort($columns);

        self::assertSame(
            ['field_id', 'object_id', 'object_kind', 'value_date', 'value_dec', 'value_int', 'value_text'],
            $columns,
        );

        $indexes = $this->indexNames($this->valuesTable);
        sort($indexes);

        self::assertSame(['PRIMARY', 'field_date', 'field_dec', 'field_int', 'field_text'], $indexes);
    }

    public function testTheValueTableDeclaresInnoDB(): void
    {
        $this->dropTables();
        (new CreateFieldValueTable($this->connection(), new DdlEmitter()))->up();

        $showCreate = $this->showCreate($this->valuesTable);

        self::assertStringContainsString('ENGINE=InnoDB', $showCreate);
    }

    public function testUpCreatesTheItemsTableWithTheDeclaredShape(): void
    {
        $this->dropTables();
        (new CreateFieldItemTable($this->connection(), new DdlEmitter()))->up();

        self::assertTrue($this->tableExists($this->itemsTable));

        $columns = $this->columnNames($this->itemsTable);
        sort($columns);

        self::assertSame(
            ['field_id', 'object_id', 'object_kind', 'position', 'value_int', 'value_text'],
            $columns,
        );

        self::assertStringContainsString('ENGINE=InnoDB', $this->showCreate($this->itemsTable));
    }

    public function testDownDropsBothTables(): void
    {
        $connection = $this->connection();
        $emitter = new DdlEmitter();
        $this->dropTables();

        (new CreateFieldValueTable($connection, $emitter))->up();
        (new CreateFieldItemTable($connection, $emitter))->up();

        self::assertTrue($this->tableExists($this->valuesTable));
        self::assertTrue($this->tableExists($this->itemsTable));

        (new CreateFieldItemTable($connection, $emitter))->down();
        (new CreateFieldValueTable($connection, $emitter))->down();

        self::assertFalse($this->tableExists($this->valuesTable));
        self::assertFalse($this->tableExists($this->itemsTable));
    }

    public function testTheMigrationNamesAreStable(): void
    {
        self::assertSame('mahout/fields/value_table', (new CreateFieldValueTable($this->connection(), new DdlEmitter()))->name());
        self::assertSame('mahout/fields/item_table', (new CreateFieldItemTable($this->connection(), new DdlEmitter()))->name());
    }

    private function showCreate(\Iniznet\Mahout\Db\Table $table): string
    {
        global $wpdb;

        return (string) $wpdb->get_row('SHOW CREATE TABLE '.$table->name->quoted(), ARRAY_A)['Create Table'];
    }
}
