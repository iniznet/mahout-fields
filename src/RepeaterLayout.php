<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * The admin layout a repeater's control renders with. Exactly two ship with
 * the package; a consumer wanting something else writes its own control and
 * registers it through the editor_controls filter.
 *
 * Stacked renders one card per item, the members stacked vertically — the
 * shape that reads well for long values and for nested repeaters. Rows
 * renders one row per item with the member cells inline; a nested repeater
 * member renders Stacked inside its cell.
 */
enum RepeaterLayout: string
{
    case Stacked = 'stacked';
    case Rows = 'rows';
}
