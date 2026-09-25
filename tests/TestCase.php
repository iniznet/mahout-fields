<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests;

use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Internal\WpdbConnection;
use Iniznet\Mahout\Db\Internal\WpdbTableGateway;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Fields\Contracts\FieldReader;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldWriter;
use Iniznet\Mahout\Fields\FieldLeavesTable;
use Iniznet\Mahout\Fields\FieldValuesTable;
use Iniznet\Mahout\Fields\Internal\GroupSnapshot;
use Iniznet\Mahout\Fields\Internal\MetaStorage;
use Iniznet\Mahout\Fields\Internal\RevisionMirror;
use Iniznet\Mahout\Fields\Internal\RevisionRestorer;
use Iniznet\Mahout\Fields\Internal\TableStorage;

/**
 * The base test case for this package.
 *
 * The two field tables are created per test, so core's query filter rewrites
 * them to temporary tables and nothing leaks into the test database. The
 * reader and writer under test are built here against the real gateway, so
 * every round-trip in this suite exercises the exact production path.
 *
 * @internal
 */
abstract class TestCase extends \WP_UnitTestCase
{
    protected FieldRegistry $registry;

    protected FieldReader $reader;

    protected FieldWriter $writer;

    protected TableGateway $gateway;

    protected Table $valuesTable;

    protected Table $leavesTable;

    private WpdbConnection $connection;

    protected RevisionMirror $mirror;

    protected GroupSnapshot $snapshot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = WpdbConnection::inWordPress();
        $this->gateway = new WpdbTableGateway($this->connection);
        $this->valuesTable = FieldValuesTable::table($this->connection->prefix(), $this->connection->charsetCollate());
        $this->leavesTable = FieldLeavesTable::table($this->connection->prefix(), $this->connection->charsetCollate());

        $this->dropTables();
        $this->createTables();

        $meta = new MetaStorage();
        $table = new TableStorage($this->gateway, $this->valuesTable, $this->leavesTable);
        $mirror = new RevisionMirror();
        $this->registry = new \Iniznet\Mahout\Fields\FieldRegistry();
        $this->reader = new \Iniznet\Mahout\Fields\FieldReader($this->registry, $meta, $table, $mirror);
        $this->writer = new \Iniznet\Mahout\Fields\FieldWriter(
            $this->registry,
            $meta,
            $table,
            $this->gateway,
            $mirror,
            new GroupSnapshot($this->registry, $table),
        );
        $this->mirror = $mirror;
        $this->snapshot = new GroupSnapshot($this->registry, $table);
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    protected function connection(): WpdbConnection
    {
        return $this->connection;
    }

    protected function restorer(): RevisionRestorer
    {
        return new RevisionRestorer(
            $this->registry,
            $this->gateway,
            new TableStorage($this->gateway, $this->valuesTable, $this->leavesTable),
            $this->mirror,
        );
    }

    protected function postId(): int
    {
        return (int) self::factory()->post->create();
    }

    protected function userId(): int
    {
        return (int) self::factory()->user->create();
    }

    protected function termId(): int
    {
        return (int) self::factory()->category->create();
    }

    protected function valuesTableName(): string
    {
        return $this->valuesTable->name->value;
    }

    protected function itemsTableName(): string
    {
        return $this->itemsTable->name->value;
    }

    /**
     * A production-threshold Diagnostics: warning refusals record without
     * touching the error log, and a test reads them back through records().
     */
    protected function diagnostics(): \Iniznet\Mahout\Kernel\Diagnostics
    {
        return new \Iniznet\Mahout\Kernel\Diagnostics(
            environment: new \Iniznet\Mahout\Kernel\Environment(type: 'production', debug: false, developmentMode: false),
            queries: new Fixtures\CountingQuerySource(),
        );
    }

    /**
     * One raw row of a field table, by its key columns, or null when absent.
     *
     * @return array<string, string|null>|null
     */
    protected function rawValueRow(string $fieldId, int $objectId): ?array
    {
        global $wpdb;

        $table = $this->valuesTable;

        $statement = 'SELECT * FROM '.$table->name->quoted()
            .' WHERE '.$table->column('field_id')->name->quoted().' = %s'
            .' AND '.$table->column('object_id')->name->quoted().' = %d';

        $row = $wpdb->get_row($wpdb->prepare($statement, $fieldId, $objectId), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** @return list<string> */
    protected function columnNames(Table $table): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SHOW COLUMNS FROM '.$table->name->quoted(), ARRAY_A);

        $names = [];
        foreach ((array) $rows as $row) {
            $name = is_array($row) ? ($row['Field'] ?? null) : null;
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return list<string> */
    protected function indexNames(Table $table): array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SHOW INDEX FROM '.$table->name->quoted(), ARRAY_A);

        $names = [];
        foreach ((array) $rows as $row) {
            $name = is_array($row) ? ($row['Key_name'] ?? null) : null;
            if (is_string($name) && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    protected function tableExists(Table $table): bool
    {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
            $table->name->value,
        ));

        return $found === $table->name->value;
    }

    protected function dropTables(): void
    {
        global $wpdb;

        $wpdb->query('DROP TABLE IF EXISTS '.$this->valuesTable->name->quoted());
        $wpdb->query('DROP TABLE IF EXISTS '.$this->leavesTable->name->quoted());
    }

    protected function createTables(): void
    {
        $emitter = new DdlEmitter();
        $this->connection->execute($emitter->create($this->valuesTable));
        $this->connection->execute($emitter->create($this->leavesTable));
    }

    /**
     * @return array<string, string|null>|null
     */
    protected function rawLeaf(string $groupId, int $objectId, string $relative): ?array
    {
        global $wpdb;

        $table = $this->leavesTable;

        $statement = 'SELECT * FROM '.$table->name->quoted()
            .' WHERE '.$table->column('group_id')->name->quoted().' = %s'
            .' AND '.$table->column('object_id')->name->quoted().' = %d'
            .' AND '.$table->column('address')->name->quoted().' = %s';

        $row = $wpdb->get_row($wpdb->prepare($statement, $groupId, $objectId, $relative), ARRAY_A);

        return is_array($row) ? $row : null;
    }
}
