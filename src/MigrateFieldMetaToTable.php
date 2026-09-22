<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Internal\FieldMover;

/**
 * Moves one field from Meta storage to the Table target: up() copies every
 * stored value into the declared tables and removes the meta rows, down()
 * moves them back. Both directions run through the adapters, each chunk is one
 * transaction on mahout-db's gateway, and the reversal is value-exact.
 *
 * Register it through mahout/db/migrations for the field whose declared
 * StorageTarget has changed; the ledger's UNIQUE name carries the field id.
 * The run paths are mahout-db's -- the CLI, the theme switch and the lazy
 * admin path -- never a front-end request.
 */
final readonly class MigrateFieldMetaToTable implements Migration
{
    private readonly FieldMover $mover;

    public function __construct(
        private readonly FieldRegistry $registry,
        private readonly TableGateway $gateway,
        private readonly SqlConnection $connection,
        private readonly string $fieldId,
    ) {
        $this->mover = new FieldMover($registry, $gateway, $connection);
    }

    public function name(): string
    {
        return 'mahout/fields/meta_to_table/'.$this->fieldId;
    }

    public function up(): void
    {
        $this->mover->metaToTable($this->fieldId);
    }

    public function down(): void
    {
        $this->mover->tableToMeta($this->fieldId);
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }
}
