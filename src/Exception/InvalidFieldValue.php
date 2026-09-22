<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A value cannot be sanitised into the field's declared type: a non-numeric
 * integer, an unparseable date, a choice outside the declared set, an email
 * that sanitises to nothing. The write is refused, never coerced silently.
 */
final class InvalidFieldValue extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $fieldId,
        private readonly string $type,
    ) {
        parent::__construct($message);
    }

    public static function notNumeric(string $fieldId, string $type, string $value): self
    {
        return new self(sprintf('Field "%s" (%s) refused the non-numeric value "%s".', $fieldId, $type, $value), $fieldId, $type);
    }

    public static function unparseableDate(string $fieldId, string $value): self
    {
        return new self(sprintf('Field "%s" (date) refused the unparseable value "%s"; declare Y-m-d or Y-m-d H:i:s.', $fieldId, $value), $fieldId, 'date');
    }

    public static function outsideChoices(string $fieldId, string $value): self
    {
        return new self(sprintf('Field "%s" (choice) refused "%s"; it is not one of the declared options.', $fieldId, $value), $fieldId, 'choice');
    }

    public static function refused(string $fieldId, string $type, string $value, string $reason): self
    {
        return new self(sprintf('Field "%s" (%s) refused "%s": %s.', $fieldId, $type, $value, $reason), $fieldId, $type);
    }

    public static function emptyEmail(string $fieldId, string $value): self
    {
        return new self(sprintf('Field "%s" (email) refused "%s"; it sanitises to nothing.', $fieldId, $value), $fieldId, 'email');
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function type(): string
    {
        return $this->type;
    }
}
