<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Field;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;

/**
 * The Meta storage adapter: wp_postmeta, wp_usermeta, wp_termmeta and
 * wp_options.
 *
 * The canonical storage shape is the string. A scalar arrives as its field's
 * PHP shape and leaves as the canonical text form; the field's cast() turns it
 * back on read. Absence is a real state: a read uses the raw meta lookup, so
 * an absent key and a stored empty string stay distinguishable, and a write of
 * null deletes the key rather than storing an empty placeholder.
 *
 * This class is the only place outside core that touches the meta API for a
 * registered field, and it sits inside the field layer by construction.
 *
 * @internal
 */
final readonly class MetaStorage
{
    private const string OPTION_PREFIX = 'mahout_fields';

    /**
     * The RAW stored value: a string when present, null when absent. A
     * non-scalar under a registered key is not this package's shape and is
     * refused rather than coerced.
     */
    public function read(Field $field, ObjectRef $object): string|int|float|bool|null
    {
        $raw = match ($object->context) {
            ObjectContext::Option => \get_option($this->optionKey($field), null),
            ObjectContext::Post, ObjectContext::User, ObjectContext::Term => $this->readMeta($object, $field->id),
        };

        if (null === $raw) {
            return null;
        }

        if (!\is_scalar($raw)) {
            throw InvalidFieldWrite::unreadableMeta($field->id);
        }

        return $raw;
    }

    public function write(Field $field, ObjectRef $object, string|int|float|bool $value): void
    {
        $canonical = $this->canonical($value);

        $written = match ($object->context) {
            ObjectContext::Option => \update_option($this->optionKey($field), $canonical),
            ObjectContext::Post => \update_post_meta($object->id, $field->id, $canonical),
            ObjectContext::User => \update_user_meta($object->id, $field->id, $canonical),
            ObjectContext::Term => \update_term_meta($object->id, $field->id, $canonical),
        };

        // Core returns false both for a refused write and for an unchanged
        // value, so a false return is verified before it is believed: the
        // stored value must match what this write asked to store.
        if (!$written && $canonical !== $this->storedString($field, $object)) {
            throw InvalidFieldWrite::metaRefused($field->id);
        }
    }

    public function delete(Field $field, ObjectRef $object): void
    {
        match ($object->context) {
            ObjectContext::Option => \delete_option($this->optionKey($field)),
            ObjectContext::Post => \delete_post_meta($object->id, $field->id),
            ObjectContext::User => \delete_user_meta($object->id, $field->id),
            ObjectContext::Term => \delete_term_meta($object->id, $field->id),
        };
    }

    private function readMeta(ObjectRef $object, string $key): string|int|float|bool|null
    {
        $raw = \get_metadata_raw($object->context->value, $object->id, $key);

        if (null === $raw || false === $raw) {
            return null;
        }

        if (!\is_array($raw) || [] === $raw) {
            throw InvalidFieldWrite::unreadableMeta($key);
        }

        $first = $raw[0];

        if (!\is_scalar($first)) {
            throw InvalidFieldWrite::unreadableMeta($key);
        }

        return $first;
    }

    private function storedString(Field $field, ObjectRef $object): ?string
    {
        $raw = match ($object->context) {
            ObjectContext::Option => \get_option($this->optionKey($field), null),
            ObjectContext::Post, ObjectContext::User, ObjectContext::Term => $this->readMeta($object, $field->id),
        };

        return \is_scalar($raw) ? (string) $raw : null;
    }

    private function optionKey(Field $field): string
    {
        return self::OPTION_PREFIX.'/'.$field->id;
    }

    private function canonical(string|int|float|bool $value): string
    {
        return \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
