<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;

/**
 * An email address. Sanitised on write with core's email sanitiser; an input
 * that sanitises to nothing is refused rather than stored as an empty value.
 */
final readonly class EmailField extends Field
{
    public function __construct(string $id, StorageTarget $storage)
    {
        parent::__construct($id, $storage);
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Email;
    }

    #[\Override]
    public function sanitise(string|int|float|bool|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $input = (string) $value;
        $sanitised = \sanitize_email($input);

        if ('' === $sanitised && '' !== \sanitize_text_field($input)) {
            throw InvalidFieldValue::emptyEmail($this->id, $input);
        }

        return $sanitised;
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?string
    {
        return null === $raw ? null : (string) $raw;
    }
}
