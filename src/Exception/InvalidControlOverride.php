<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A field UI policy named a control that is not a control. The policy is a
 * trust boundary like the editor_controls filter: the wrong shape is refused
 * at the render site, never coerced and never silently skipped.
 */
final class InvalidControlOverride extends \LogicException implements MahoutException
{
    public static function notAControl(string $fieldId, string $given): self
    {
        return new self(sprintf(
            'The UI policy for field "%s" names "%s", which does not implement %s.',
            $fieldId,
            $given,
            \Iniznet\Mahout\Fields\Contracts\FieldControl::class,
        ));
    }
}
