<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin\Control;

use Iniznet\Mahout\Fields\Admin\FieldControlProps;
use Iniznet\Mahout\Fields\Contracts\FieldControl;

/** The repeater control: one positioned input per stored item. */
final class RepeaterControl implements FieldControl
{
    #[\Override]
    public function render(FieldControlProps $props): string
    {
        \ob_start();
        $control = $props;
        require __DIR__.'/markup/repeater.php';

        return (string) \ob_get_clean();
    }
}
