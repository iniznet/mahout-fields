<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;

/**
 * A calendar date, canonicalised to Y-m-d. A consumer may hand in a
 * DateTimeInterface, a string in the canonical form, or a null; anything else
 * is a loud refusal, because a mangled date stored silently is worse than a
 * failed write.
 */
final readonly class DateField extends Field
{
    private const string FORMAT = 'Y-m-d';

    public function __construct(string $id, StorageTarget $storage)
    {
        parent::__construct($id, $storage);
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Date;
    }

    #[\Override]
    public function sanitise(string|int|float|bool|\DateTimeInterface|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::FORMAT);
        }

        if (!\is_string($value)) {
            throw InvalidFieldValue::unparseableDate($this->id, (string) $value);
        }

        $parsed = \DateTimeImmutable::createFromFormat(self::FORMAT.'|', $value, new \DateTimeZone('UTC'));

        if (false === $parsed) {
            throw InvalidFieldValue::unparseableDate($this->id, $value);
        }

        $errors = \DateTimeImmutable::getLastErrors();

        if (\is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) {
            throw InvalidFieldValue::unparseableDate($this->id, $value);
        }

        return $parsed->format(self::FORMAT);
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        // A DATETIME column pads a date with 00:00:00 on the way back; the
        // canonical PHP shape of this field is the calendar date alone.
        return \is_string($raw) ? \substr($raw, 0, 10) : (string) $raw;
    }
}
