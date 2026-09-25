<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * A repeater's composite item: the fields every item carries, in declared
 * order. A scalar-item repeater needs no RepeaterItem — it passes its one
 * field directly.
 *
 * The value carries no validation of its own: the declaring RepeaterField
 * enforces the whole subtree's rules — count, Carried targets, member-id
 * uniqueness across the nesting, the depth cap — because those rules need the
 * root's id to name a refusal.
 *
 * @param list<Field> $fields
 */
final readonly class RepeaterItem
{
    /**
     * @param list<Field> $fields
     */
    public function __construct(
        public array $fields,
    ) {
    }
}
