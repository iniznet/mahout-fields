<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;

/**
 * One value out of a declared, closed set. The set is declared at declaration
 * time and enforced at every write; a value outside it is refused, never
 * coerced, because a choice that silently becomes an empty string is a
 * corrupted enum.
 *
 * @param list<string> $options
 */
final readonly class ChoiceField extends Field
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        string $id,
        StorageTarget $storage,
        public array $options,
    ) {
        parent::__construct($id, $storage);

        if ([] === $options) {
            throw InvalidFieldDefinition::emptyChoiceSet($id);
        }
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Choice;
    }

    #[\Override]
    public function sanitise(string|int|float|bool|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $input = (string) $value;

        if (!\in_array($input, $this->options, true)) {
            throw InvalidFieldValue::outsideChoices($this->id, $input);
        }

        return $input;
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?string
    {
        return null === $raw ? null : (string) $raw;
    }
}
