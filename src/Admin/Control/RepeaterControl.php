<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin\Control;

use Iniznet\Mahout\Fields\Admin\FieldControlProps;
use Iniznet\Mahout\Fields\Contracts\FieldControl;
use Iniznet\Mahout\Fields\RepeaterLayout;

/**
 * The repeater's control. Exactly two layouts ship: Stacked, one card per
 * item with the members stacked vertically, and Rows, one row per item with
 * the member cells inline — a nested repeater renders Stacked inside its
 * cell. A consumer wanting something else registers its own control through
 * the editor_controls filter; the package ships no third layout to maintain.
 */
final class RepeaterControl implements FieldControl
{
    #[\Override]
    public function render(FieldControlProps $props): string
    {
        \ob_start();
        $control = $props;
        require __DIR__.'/markup/'.(RepeaterLayout::Rows === $props->layout ? 'repeater-rows.php' : 'repeater-stacked.php');

        return (string) \ob_get_clean();
    }
}
