<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\FieldType;

/**
 * One control's typed props: everything the control renders and nothing it
 * must not see. No WP_Post, no request, no collaborator -- a control is
 * testable without WordPress.
 */
final readonly class FieldControlProps
{
    /**
     * @param list<string>                     $options the choice field's closed set
     * @param list<string|int|float|bool|null> $items   the repeater's stored items, in position order
     */
    public function __construct(
        public string $fieldId,
        public FieldType $type,
        public string $label,
        public string $inputName,
        public string $inputId,
        public string|int|float|bool|null $value = null,
        public ?string $emptyLabel = null,
        public ?string $error = null,
        public ?string $placeholder = null,
        public bool $required = false,
        public bool $disabled = false,
        public array $options = [],
        public array $items = [],
    ) {
    }

    /**
     * The value canonicalised for an input's value attribute: the one place a
     * scalar becomes text, so a markup file never casts and never guesses.
     */
    public function valueString(): string
    {
        return null === $this->value ? '' : (string) $this->value;
    }
}
