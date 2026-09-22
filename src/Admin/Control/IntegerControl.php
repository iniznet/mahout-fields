<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin\Control;

use Iniznet\Mahout\Fields\Admin\FieldControlProps;
use Iniznet\Mahout\Fields\Contracts\FieldControl;

/** The whole-number control. */
final class IntegerControl implements FieldControl
{
    #[\Override]
    public function render(FieldControlProps $props): string
    {
        \ob_start();
        $control = $props;
        require __DIR__.'/markup/integer.php';

        return (string) \ob_get_clean();
    }
}
