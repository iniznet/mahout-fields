<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * One declared field. Storage is a required constructor parameter with no
 * default: omitting it is a refusal at the declaration site, not a default
 * discovered later.
 *
 * A field is a value object. It holds no collaborators and resolves none, so
 * nothing here is service access. Sanitisation is the field's own job; the
 * adapters never sanitise.
 */
abstract readonly class Field
{
    private const int MAX_ID_LENGTH = 64;

    private const string ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param non-empty-string $id
     */
    protected function __construct(
        public string $id,
        public StorageTarget $storage,
    ) {
        self::assertId('field', $id);
    }

    abstract public function type(): FieldType;

    /**
     * The field's own sanitiser: caller input to the field's PHP shape. The
     * adapters canonicalise that shape for their backend and never sanitise
     * themselves, so there is exactly one sanitisation per value.
     */
    abstract public function sanitise(string|int|float|bool|null $value): string|int|float|bool|null;

    /**
     * The field's PHP shape for a sanitised raw value. The storage backends
     * hand back strings; the field knows its own shape, so the reader never
     * asks a consumer to cast.
     */
    abstract public function cast(string|int|float|bool|null $raw): string|int|float|bool|null;

    /**
     * @param string           $kind 'group' or 'field'
     * @param non-empty-string $id
     *
     * @throws Exception\InvalidFieldId
     */
    protected static function assertId(string $kind, string $id): void
    {
        if (strlen($id) > self::MAX_ID_LENGTH) {
            throw Exception\InvalidFieldId::tooLong($kind, $id, self::MAX_ID_LENGTH);
        }

        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw Exception\InvalidFieldId::malformed($kind, $id);
        }
    }
}
