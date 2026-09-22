<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * A short single-line text value. Sanitised on write with core's own text
 * sanitiser; the PHP shape is the string itself.
 */
final readonly class TextField extends Field
{
    public function __construct(string $id, StorageTarget $storage)
    {
        parent::__construct($id, $storage);
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Text;
    }

    #[\Override]
    public function sanitise(string|int|float|bool|null $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return \sanitize_text_field((string) $value);
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?string
    {
        return null === $raw ? null : (string) $raw;
    }
}
