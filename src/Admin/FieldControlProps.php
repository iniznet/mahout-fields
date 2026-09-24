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
        public bool $styled = true,
    ) {
    }

    /**
     * The element's class list. A styled control carries the package's
     * default classes; an unstyled one carries one neutral marker no default
     * rule targets, so the host's takeover is a markup fact, not a cascade
     * fight. The markup files ask this helper instead of branching, so there
     * is one place the styled decision becomes markup.
     *
     * @param string ...$styled the classes a styled control renders
     */
    public function classes(string ...$styled): string
    {
        if (!$this->styled) {
            return 'mahout-fields-unstyled';
        }

        return implode(' ', $styled);
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
