<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Db\DdlEmitter;
use Iniznet\Mahout\Db\Hooks as DbHooks;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Fields\Contracts\FieldReader as FieldReaderContract;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry as FieldRegistryContract;
use Iniznet\Mahout\Fields\Contracts\FieldWriter as FieldWriterContract;
use Iniznet\Mahout\Fields\Internal\MetaStorage;
use Iniznet\Mahout\Fields\Internal\PostItemOrphans;
use Iniznet\Mahout\Fields\Internal\PostValueOrphans;
use Iniznet\Mahout\Fields\Internal\TableStorage;
use Iniznet\Mahout\Kernel\Container;
use Iniznet\Mahout\Kernel\Contracts\ServiceProvider;

/**
 * The composition-root entry point for mahout-fields.
 *
 * The reader and the writer are declared under the Contracts interfaces a
 * consumer depends on; the two storage adapters and the registry are package
 * internals. mahout-db must register before this provider, because the
 * connection and the gateway are resolved here from the container under their
 * contract ids -- an explicit composition-root ordering, stated in the README.
 *
 * The two tables' migrations and the post-kind orphan sources are attached to
 * mahout-db's filters in register(), which runs for every provider before any
 * boot, so the db package's boot always sees them.
 *
 * Nothing here renders an editor control, binds a REST route or registers a
 * WP-CLI command: those are the save-lifecycle and editor slices, and their
 * seams are the writer's guard order and the EDITOR_CONTROLS filter.
 */
final class FieldsProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $connection = $container->get(SqlConnection::class);
        $gateway = $container->get(TableGateway::class);
        $emitter = new DdlEmitter();

        $valuesTable = FieldValuesTable::table($connection->prefix(), $connection->charsetCollate());
        $itemsTable = FieldItemsTable::table($connection->prefix(), $connection->charsetCollate());

        $meta = new MetaStorage();
        $table = new TableStorage($gateway, $valuesTable, $itemsTable);
        $registry = new FieldRegistry();

        $container->set(service: $registry, id: FieldRegistryContract::class);
        $container->set(service: new FieldReader($registry, $meta, $table), id: FieldReaderContract::class);
        $container->set(service: new FieldWriter($registry, $meta, $table), id: FieldWriterContract::class);

        $this->attachMigrations($connection, $emitter);
        $this->attachOrphanSources($connection, $valuesTable, $itemsTable);
    }

    public function boot(Container $container): void
    {
        \do_action(Hooks::REGISTRY_LOADED, $container->get(FieldRegistryContract::class));
    }

    /**
     * The two tables' migrations join the db package's list through its own
     * filter, in register() -- which runs for every provider before any boot,
     * so the order the theme lists the two providers in cannot lose them.
     */
    private function attachMigrations(SqlConnection $connection, DdlEmitter $emitter): void
    {
        $valueTable = new CreateFieldValueTable($connection, $emitter);
        $itemTable = new CreateFieldItemTable($connection, $emitter);

        \add_filter(
            DbHooks::MIGRATIONS,
            static fn (array $migrations): array => [...$migrations, $valueTable, $itemTable],
            priority: 10,
            accepted_args: 1,
        );
    }

    /**
     * The post-kind orphan sources for both tables. User- and term-scoped rows
     * are out of this contract's scope; their collection is the privacy
     * slice's work, alongside the erasure paths that create it.
     */
    private function attachOrphanSources(
        SqlConnection $connection,
        Table $valuesTable,
        Table $itemsTable,
    ): void {
        $valueSource = new PostValueOrphans($connection, $valuesTable);
        $itemSource = new PostItemOrphans($connection, $itemsTable);

        \add_filter(
            DbHooks::ORPHAN_SOURCES,
            static function (array $sources) use ($valueSource, $itemSource): array {
                $sources[] = $valueSource;
                $sources[] = $itemSource;

                return $sources;
            },
            priority: 10,
            accepted_args: 1,
        );
    }
}
