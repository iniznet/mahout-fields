<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;

/**
 * A repeater: one declared scalar field, repeated.
 *
 * The Meta target stores the versioned JSON payload a codec encodes; the
 * Table target binds the items table, one row per item at an explicit
 * position. The two shapes are the two strategies the storage contract names,
 * and a repeater that is queried may only take the second.
 *
 * The item must be a scalar field — a repeater of repeaters has no generic
 * row shape, and is the documented trigger for a dedicated typed table.
 */
final readonly class RepeaterField extends Field
{
    public function __construct(
        string $id,
        StorageTarget $storage,
        public Field $item,
        public ?int $expectedMaxItems = null,
        public bool $queried = false,
        ?PersonalData $personalData = null,
        ?string $label = null,
    ) {
        parent::__construct($id, $storage, $personalData, $label);

        if ($item instanceof RepeaterField) {
            throw InvalidFieldDefinition::repeaterOfRepeater($id);
        }

        if (null !== $expectedMaxItems && $expectedMaxItems < 1) {
            throw InvalidFieldDefinition::itemExpectation($id, $expectedMaxItems);
        }
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Repeater;
    }

    /**
     * A repeater's stored value is the versioned payload, validated but never
     * re-encoded: the payload bytes are the codec's, not the field layer's.
     */
    #[\Override]
    public function sanitise(string|int|float|bool|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw InvalidFieldWrite::scalarIntoRepeater($this->id);
        }

        RepeaterCodec::assertPayload($value);

        return $value;
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?string
    {
        return null === $raw ? null : (string) $raw;
    }
}
