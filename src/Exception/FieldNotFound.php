<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * No registered field carries this id. Reading a field that was never
 * declared is a programmer error, not an expected absence.
 */
final class FieldNotFound extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $fieldId,
    ) {
        parent::__construct($message);
    }

    public static function forId(string $fieldId): self
    {
        return new self(sprintf('Field "%s" is not registered.', $fieldId), $fieldId);
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }
}
