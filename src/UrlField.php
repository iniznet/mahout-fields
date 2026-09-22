<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;

/**
 * A URL, sanitised on write with esc_url_raw() — the storage-safe form. An
 * input that carries text but sanitises to nothing is refused.
 */
final readonly class UrlField extends Field
{
    public function __construct(string $id, StorageTarget $storage, ?PersonalData $personalData = null, ?string $label = null)
    {
        parent::__construct($id, $storage, $personalData, $label);
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Url;
    }

    #[\Override]
    public function sanitise(string|int|float|bool|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $input = (string) $value;
        $sanitised = \esc_url_raw($input);

        if ('' === $sanitised && '' !== \sanitize_text_field($input)) {
            throw InvalidFieldValue::refused($this->id, 'url', $input, 'it sanitises to no URL');
        }

        return $sanitised;
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?string
    {
        return null === $raw ? null : (string) $raw;
    }
}
