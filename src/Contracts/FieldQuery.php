<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Operator;
use Iniznet\Mahout\Fields\OrderDirection;

/**
 * The bounded field query shapes: the three reads a Surface composes a
 * two-phase query from, and nothing else. Every statement is built from the
 * declared table's identifiers, carries every value through a placeholder and
 * is bounded by an explicit LIMIT -- including the aggregate, which counts over
 * a capped inner read rather than over the whole range. There is no unbounded
 * statement in this contract, so a caller cannot reach the one query shape that
 * scales with the size of the data rather than with the size of the page.
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
     * operator, counted over at most $ceiling rows: the answer is exact up to the
     * ceiling, and equal to the ceiling when at least that many match. A caller
     * that needs an exact total past the ceiling is asking for a maintained
     * counter, not for a wider scan, because the scan's cost is the range.
     *
     * @param int $ceiling the inner read's LIMIT; a non-positive one is refused with UnboundedStatement
     */
    public function countUpTo(string $fieldId, Operator $operator, string|int|float|bool|null $value, int $ceiling): int;

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
