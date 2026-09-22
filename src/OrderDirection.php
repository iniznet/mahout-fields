<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * The two index directions an ordered field query can take. The direction is
 * an enum, never a string: an ORDER BY composed from caller text is an
 * identifier injection, and there is no third order.
 */
enum OrderDirection: string
{
    case Ascending = 'ASC';

    case Descending = 'DESC';

    /** The clause fragment, from the declaration and never from caller text. */
    public function clause(): string
    {
        return $this->value;
    }
}
