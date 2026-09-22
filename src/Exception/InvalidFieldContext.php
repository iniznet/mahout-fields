<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A field's group context and the object a caller named do not agree: a user
 * field read with a post id, an option field addressed with a row object.
 * The mismatch is a caller bug and is refused, never coerced.
 */
final class InvalidFieldContext extends \LogicException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $fieldId,
        private readonly string $declared,
        private readonly string $given,
    ) {
        parent::__construct($message);
    }

    public static function mismatch(string $fieldId, string $declared, string $given): self
    {
        return new self(sprintf('Field "%s" is declared for the %s context; the call named %s.', $fieldId, $declared, $given), $fieldId, $declared, $given);
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function declared(): string
    {
        return $this->declared;
    }

    public function given(): string
    {
        return $this->given;
    }
}
