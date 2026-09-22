<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;

/**
 * A flag. The canonical storage form is 1 and 0, never an empty string, so a
 * boolean reads back as the same boolean on both targets. The empty string is
 * accepted on write and means false, because that is what an unticked form
 * control submits.
 */
final readonly class BooleanField extends Field
{
    public function __construct(string $id, StorageTarget $storage, ?PersonalData $personalData = null, ?string $label = null)
    {
        parent::__construct($id, $storage, $personalData, $label);
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Boolean;
    }

    #[\Override]
    public function sanitise(string|int|float|bool|null $value): ?bool
    {
        if (null === $value) {
            return null;
        }

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            if (0 === $value || 1 === $value) {
                return 1 === $value;
            }
        } elseif (\is_string($value)) {
            return match ($value) {
                '1', 'true' => true,
                '0', '', 'false' => false,
                default => null,
            };
        }

        throw InvalidFieldValue::refused($this->id, 'boolean', (string) $value, 'it is not a boolean shape');
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?bool
    {
        return null === $raw ? null : 1 === (int) $raw;
    }
}
