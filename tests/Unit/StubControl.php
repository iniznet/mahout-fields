<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Admin\FieldControlProps;
use Iniznet\Mahout\Fields\Contracts\FieldControl;

/**
 * A minimal host-side control: the marker attribute proves a replacement
 * rendered and the built-in did not.
 *
 * @internal
 */
final class StubControl implements FieldControl
{
    #[\Override]
    public function render(FieldControlProps $props): string
    {
        return '<div data-stub-field="'.\esc_attr($props->fieldId).'"></div>';
    }
}
