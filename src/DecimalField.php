<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;

/**
 * A decimal number with six fixed places — the value_dec column's own shape.
 * The canonical storage form is the %.6F string, which is locale-independent
 * and survives the decimal(20,6) column without a second rounding.
 */
final readonly class DecimalField extends Field
{
    public function __construct(string $id, StorageTarget $storage)
    {
        parent::__construct($id, $storage);
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Decimal;
    }

    #[\Override]
    public function sanitise(string|int|float|bool|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (\is_bool($value) || !\is_numeric($value)) {
            throw InvalidFieldValue::notNumeric($this->id, 'decimal', (string) $value);
        }

        return sprintf('%.6F', (float) $value);
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?float
    {
        return null === $raw ? null : (float) $raw;
    }
}
