<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * A multi-line text value. Sanitised on write with core's textarea sanitiser,
 * which preserves newlines.
 */
final readonly class TextAreaField extends Field
{
    public function __construct(string $id, StorageTarget $storage)
    {
        parent::__construct($id, $storage);
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::TextArea;
    }

    #[\Override]
    public function sanitise(string|int|float|bool|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return \sanitize_textarea_field((string) $value);
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?string
    {
        return null === $raw ? null : (string) $raw;
    }
}
