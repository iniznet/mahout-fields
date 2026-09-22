<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Operator;
use Iniznet\Mahout\Fields\OrderDirection;

/**
 * The bounded field query shapes: the three reads a Surface composes a
 * two-phase query from, and nothing else. Every statement is built from the
 * declared table's identifiers, carries every value through a placeholder and
 * is bounded by an explicit LIMIT -- the only exception is COUNT(*), whose
 * bound is the field_id index's own range scan, because a LIMIT on an
 * aggregate is meaningless and the aggregate is the one shape whose cost is
 * the range it counts.
 *
 * The consumed pattern is builder first, core query second: postIds() feeds
 * WP_Query with post__in and orderby post__in, and an empty id list
 * short-circuits before the second query runs. The surface, not this
 * contract, owns that second query.
 */
interface FieldQuery
{
    /**
     * The object ids whose stored value for one field compares true under the
     * operator, in no guaranteed order, at most $limit of them.
     *
     * @param string|int|float|bool|null $value the comparison value, in the field's PHP shape
     * @param int                        $limit the statement's LIMIT; a non-positive one is refused with UnboundedStatement
     *
     * @return list<int>
     */
    public function postIds(string $fieldId, Operator $operator, string|int|float|bool|null $value, int $limit): array;

    /**
     * How many object ids' stored value for one field compares true under the
     * operator. Bounded by the field_id index's range scan.
     */
    public function count(string $fieldId, Operator $operator, string|int|float|bool|null $value): int;

    /**
     * The object ids ordered by the field's value, in index order -- the
     * (field_id, <value>) secondary index covers the read, so there is no
     * filesort.
     *
     * @param int $limit the statement's LIMIT; a non-positive one is refused with UnboundedStatement
     *
     * @return list<int>
     */
    public function orderedIds(string $fieldId, OrderDirection $direction, int $limit): array;
}
