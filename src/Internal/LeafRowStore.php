<?php

/**
 * The per-request leaves behind a primed repeater, filed by object and group.
 *
 * This is the value store's shape with one difference that decides its existence:
 * a scalar field is looked up by id, so its rows index to a map, while a repeater
 * is read whole and in address order, so its rows stay a list. The two are not the
 * same store wearing one key, and the read that asks for "every leaf of this group
 * on this object" would otherwise have to sort on the way out.
 *
 * Absence is recorded here for the same reason it is recorded there, and it matters
 * more: a repeater with no items is the common case on a listing, and a page that
 * rediscovered that fact per object would spend one statement per office to learn
 * that no office has any.
 *
 * @internal
 */

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Fields\ObjectKind;

final class LeafRowStore
{
    /** @var array<string, list<Row>> */
    private array $groups = [];

    public static function key(ObjectKind $kind, int $objectId, string $groupId): string
    {
        return $kind->value.':'.$objectId.':'.$groupId;
    }

    /**
     * @param list<Row> $rows every leaf one object owns in one group, which may be none
     */
    public function file(string $key, array $rows): void
    {
        $this->groups[$key] = $rows;
    }

    public function primed(string $key): bool
    {
        return \array_key_exists($key, $this->groups);
    }

    /**
     * @return list<Row>|null null when the group was never filed, the list when it was
     */
    public function rows(string $key): ?array
    {
        return $this->groups[$key] ?? null;
    }
}
