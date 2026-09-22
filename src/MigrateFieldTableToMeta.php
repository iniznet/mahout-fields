<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Contracts\Migration;
use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Internal\FieldMover;

/**
 * Moves one field from Table storage back to Meta: up() writes every row of
 * the declared tables into wp_postmeta and removes the rows, down() moves them
 * forward again. Both directions run through the adapters, and the value and
 * index state the tables are left in is the declared one.
 *
 * A field whose values must be filtered or sorted belongs in the Table; this
 * migration is the rollback for a declaration that was wrong, not a storage
 * alternative.
 */
final readonly class MigrateFieldTableToMeta implements Migration
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
        return 'mahout/fields/table_to_meta/'.$this->fieldId;
    }

    public function up(): void
    {
        $this->mover->tableToMeta($this->fieldId);
    }

    public function down(): void
    {
        $this->mover->metaToTable($this->fieldId);
    }

    public function irreversibleReason(): ?string
    {
        return null;
    }
}
