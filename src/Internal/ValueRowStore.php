<?php

/**
 * The per-request rows behind a Table-stored field, filed by object.
 *
 * This is the shape `update_meta_cache()` has in core: one statement brings home
 * every row a page will read, and the field reads that follow are memory lookups.
 * The store is mutable by nature — it is filled as a page is read — so it is a
 * plain object rather than a value object, and the adapter that owns it stays
 * readonly.
 *
 * Absence is recorded, not inferred. Filing an object with no rows marks it primed
 * with nothing, so a field absent on a primed object answers null without spending a
 * query to rediscover that fact for every field on the object.
 *
 * @internal
 */

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Fields\FieldValuesTable;
use Iniznet\Mahout\Fields\ObjectKind;

final class ValueRowStore
{
    /** @var array<string, array<string, Row>> */
    private array $objects = [];

    public static function key(ObjectKind $kind, int $objectId): string
    {
        return $kind->value.':'.$objectId;
    }

    /**
     * @param list<Row> $rows every row one object owns, which may be none
     */
    public function file(string $key, array $rows): void
    {
        $filed = [];

        foreach ($rows as $row) {
            $filed[(string) $row->value(FieldValuesTable::fieldIdColumn())] = $row;
        }

        $this->objects[$key] = $filed;
    }

    public function primed(string $key): bool
    {
        return \array_key_exists($key, $this->objects);
    }

    public function row(string $key, string $fieldId): ?Row
    {
        return $this->objects[$key][$fieldId] ?? null;
    }
}
