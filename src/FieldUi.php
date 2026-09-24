<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * One field's UI decision: which control renders it, and whether the
 * package's default styling may touch it. The defaults are the package's own
 * -- the built-in control, styled -- so a host that overrides nothing gets a
 * complete, styled editing surface, and every override is declared at one
 * greppable site.
 */
final readonly class FieldUi
{
    /**
     * @param class-string $control the replacement control's class; it must
     *                              implement the ControlRegistry's control
     *                              contract and take no constructor arguments
     */
    public function __construct(
        public bool $styled = true,
        public ?string $control = null,
    ) {
    }
}
