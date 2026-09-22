<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A field declaration breaks its own type's rules: a choice field with no
 * options, a repeater whose item is itself a repeater, a negative item
 * expectation. The declaration is refused where it is written.
 */
final class InvalidFieldDefinition extends \LogicException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $fieldId,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function emptyChoiceSet(string $fieldId): self
    {
        return new self(sprintf('Choice field "%s" declares no options.', $fieldId), $fieldId, 'choices');
    }

    public static function repeaterOfRepeater(string $fieldId): self
    {
        return new self(sprintf('Repeater "%s" declares a repeater as its item; declare a dedicated table instead.', $fieldId), $fieldId, 'item');
    }

    public static function itemExpectation(string $fieldId, int $declared): self
    {
        return new self(sprintf('Repeater "%s" declares expectedMaxItems of %d; the count must be positive.', $fieldId, $declared), $fieldId, 'items');
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
