<?php

/**
 * The field tables, emptied.
 *
 * A host's test suite runs inside core's transaction, and core's transaction covers
 * the tables WordPress owns: `wp_posts`, `wp_postmeta`, and the rest. The field
 * layer's two tables are created by a migration outside that set, so rows written by
 * one test case are still there for the next one, and an assertion that counts them
 * measures every case that ran before it in the same process.
 *
 * The honest options are to give each case values no other case uses - which works,
 * and reads like arithmetic nobody will repeat when they add the sixth case - or to
 * empty the tables between cases, which is what this is for. It is a fixture
 * boundary, not a production service: it is constructed with the connection a host
 * already holds and issues two statements, and it is absent from every request path.
 *
 * When a table is missing the statement fails loudly with the database's own words
 * naming it. That state means the migration did not run or the ledger lied about it,
 * and a test bootstrap should say so once, before any case, rather than have each
 * case rediscover it as an empty result set.
 */

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\TestSupport;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Fields\FieldLeavesTable;
use Iniznet\Mahout\Fields\FieldValuesTable;

final readonly class FieldTables
{
    public function __construct(
        private SqlConnection $connection,
    ) {
    }

    /**
     * Every row in both tables, gone. TRUNCATE rather than DELETE: the tables are
     * InnoDB and a DELETE of a whole table is a logged statement whose cost grows
     * with the rows, which is the opposite of what a fixture reset is for.
     */
    public function reset(): void
    {
        $prefix = $this->connection->prefix();
        $collate = $this->connection->charsetCollate();

        foreach ([FieldValuesTable::table($prefix, $collate), FieldLeavesTable::table($prefix, $collate)] as $table) {
            $this->connection->execute('TRUNCATE TABLE '.$table->name->quoted());
        }
    }
}
