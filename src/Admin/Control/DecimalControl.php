<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin\Control;

use Iniznet\Mahout\Fields\Admin\FieldControlProps;
use Iniznet\Mahout\Fields\Contracts\FieldControl;

/** The decimal control; the step matches the value_dec column's six places. */
final class DecimalControl implements FieldControl
{
    #[\Override]
    public function render(FieldControlProps $props): string
    {
        \ob_start();
        $control = $props;
        require __DIR__.'/markup/decimal.php';

        return (string) \ob_get_clean();
    }
}
