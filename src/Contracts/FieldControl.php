<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Admin\FieldControlProps;

/**
 * One editor control: the input an editor types into, rendered from typed
 * props and nothing else. Every field type the registry declares has one, and
 * a host adds or replaces controls through the mahout/fields/editor_controls
 * filter.
 *
 * A control is presentation: it fetches no data, touches no global, emits
 * markup only through its own markup file under src/Admin/Control/markup/,
 * and references no WordPress type.
 */
interface FieldControl
{
    public function render(FieldControlProps $props): string;
}
