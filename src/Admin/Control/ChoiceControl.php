<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin\Control;

use Iniznet\Mahout\Fields\Admin\FieldControlProps;
use Iniznet\Mahout\Fields\Contracts\FieldControl;

/** The closed-set select control; the empty state is an explicit first option. */
final class ChoiceControl implements FieldControl
{
    #[\Override]
    public function render(FieldControlProps $props): string
    {
        \ob_start();
        $control = $props;
        require __DIR__.'/markup/choice.php';

        return (string) \ob_get_clean();
    }
}
