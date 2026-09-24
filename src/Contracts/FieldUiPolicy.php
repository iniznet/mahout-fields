<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

/**
 * How the host takes over the package's admin UI. The binding is optional:
 * absent, every surface renders with the package's default control and its
 * default stylesheet. Bound, the policy is the one place the take-over is
 * declared -- globally for styling, per field for either the control itself
 * or the styling alone.
 */
interface FieldUiPolicy
{
    /**
     * Whether the package enqueues its default stylesheet at all. False
     * means the host owns every pixel of field styling; no handle is
     * registered and no control carries the default classes.
     */
    public function styled(): bool;

    /**
     * The per-field overrides, keyed by field id. An absent id takes every
     * default; a present id replaces the control, the styling, or both.
     *
     * @return array<string, \Iniznet\Mahout\Fields\FieldUi>
     */
    public function fields(): array;
}
