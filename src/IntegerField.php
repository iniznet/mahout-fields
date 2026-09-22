<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;

/**
 * A whole number. A non-numeric or fractional input is refused: an integer
 * field that silently truncates 3.7 is a corrupted count.
 */
final readonly class IntegerField extends Field
{
    public function __construct(string $id, StorageTarget $storage)
    {
        parent::__construct($id, $storage);
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Integer;
    }

    #[\Override]
    public function sanitise(string|int|float|bool|null $value): ?int
    {
        if (null === $value) {
            return null;
        }

        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            if (floor($value) !== $value) {
                throw InvalidFieldValue::notNumeric($this->id, 'integer', (string) $value);
            }

            return (int) $value;
        }

        if (\is_string($value) && 1 === \preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        throw InvalidFieldValue::notNumeric($this->id, 'integer', (string) $value);
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?int
    {
        return null === $raw ? null : (int) $raw;
    }
}
