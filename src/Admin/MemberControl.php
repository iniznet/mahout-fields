<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Contracts\FieldControl;

/**
 * One member's rendered cell inside a repeater item: the control that knows
 * the member's type and the props that carry its value, named for its
 * position in the submitted tree. A nested repeater member is a MemberControl
 * whose control is another RepeaterControl, so the nesting renders itself.
 */
final readonly class MemberControl
{
    public function __construct(
        public FieldControl $control,
        public FieldControlProps $props,
    ) {
    }

    public function render(): string
    {
        return $this->control->render($this->props);
    }
}
