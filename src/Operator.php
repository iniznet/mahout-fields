<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * The closed set of comparison operators a field query can take. The operator
 * is an enum, never a string: a WHERE clause composed from caller text is an
 * injection, and there is no seventh comparison.
 *
 * There is deliberately no LIKE. A pattern a visitor can shape is a scan the
 * visitor can fill; the field query serves surface composition over declared
 * values, and a host that needs pattern matching writes its own bounded
 * repository statement instead.
 */
enum Operator: string
{
    case Equals = '=';

    case NotEquals = '<>';

    case LessThan = '<';

    case LessThanOrEqual = '<=';

    case GreaterThan = '>';

    case GreaterThanOrEqual = '>=';

    /** The comparison fragment, from the declaration and never from caller text. */
    public function comparison(): string
    {
        return $this->value;
    }
}
