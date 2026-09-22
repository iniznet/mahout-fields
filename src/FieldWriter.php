<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Contracts\FieldWriter as FieldWriterContract;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;

/**
 * The write path. Sanitisation happens exactly once, here, through the
 * field's own sanitiser; the adapters canonicalise and store.
 *
 * The lifecycle seam: before_save fires before sanitisation, sanitized_value
 * between sanitisation and storage, after_save after the adapter returned.
 * The save lifecycle's guards -- capability, nonce, autosave, revision, post
 * lock, lost-update hash -- arrive in a later slice and wrap these calls.
 */
final readonly class FieldWriter implements FieldWriterContract
{
    public function __construct(
        private readonly FieldRegistry $registry,
        private readonly Internal\MetaStorage $meta,
        private readonly Internal\TableStorage $table,
    ) {
    }

    public function set(string $fieldId, ObjectRef $object, string|int|float|bool|null $value): void
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);
        $field = $registered->field;

        \do_action(Hooks::BEFORE_SAVE, $fieldId, $value, $field, $object->id);

        // The filter is a trust boundary; the check is the boundary itself.
        $sanitised = \apply_filters(Hooks::SANITIZED_VALUE, $field->sanitise($value), $field, $object->id);

        if (null !== $sanitised && !\is_scalar($sanitised)) {
            throw Exception\InvalidFilterResult::notASanitisedScalar(Hooks::SANITIZED_VALUE);
        }

        if (null === $sanitised) {
            $this->remove($registered, $object);
        } else {
            match ($registered->storage) {
                StorageTarget::Meta => $this->meta->write($field, $object, $sanitised),
                StorageTarget::Table => $this->table->write($field, $object, $sanitised),
            };
        }

        \do_action(Hooks::AFTER_SAVE, $fieldId, $value, $field, $object->id);
    }

    public function setItems(string $fieldId, ObjectRef $object, array $items): void
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);

        $field = $registered->field;

        if (!$field instanceof RepeaterField) {
            throw InvalidFieldWrite::itemsIntoScalar($fieldId);
        }

        if (null !== $field->expectedMaxItems && \count($items) > $field->expectedMaxItems) {
            throw RepeaterTooLarge::items(\count($items), $field->expectedMaxItems);
        }

        \do_action(Hooks::BEFORE_SAVE, $fieldId, $items, $field, $object->id);

        match ($registered->storage) {
            StorageTarget::Meta => $this->meta->write($field, $object, RepeaterCodec::encode($this->sanitisedItems($field, $items))),
            StorageTarget::Table => $this->table->writeItems($field, $object, $items),
        };

        \do_action(Hooks::AFTER_SAVE, $fieldId, $items, $field, $object->id);
    }

    public function delete(string $fieldId, ObjectRef $object): void
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);

        \do_action(Hooks::BEFORE_DELETE, $fieldId, $registered->field, $object->id);

        $this->remove($registered, $object);

        \do_action(Hooks::AFTER_DELETE, $fieldId, $registered->field, $object->id);
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<string|int|float|bool>
     */
    private function sanitisedItems(RepeaterField $field, array $items): array
    {
        $sanitised = [];
        foreach ($items as $index => $item) {
            $value = $field->item->sanitise(\is_scalar($item) ? $item : '');

            if (null === $value) {
                throw InvalidFieldValue::refused($field->id, 'repeater item', (string) $index, 'the item sanitised to nothing');
            }

            $sanitised[] = $value;
        }

        return $sanitised;
    }

    private function remove(RegisteredField $registered, ObjectRef $object): void
    {
        $field = $registered->field;

        if ($field instanceof RepeaterField && StorageTarget::Table === $registered->storage) {
            $this->table->deleteItems($field, $object);

            return;
        }

        match ($registered->storage) {
            StorageTarget::Meta => $this->meta->delete($field, $object),
            StorageTarget::Table => $this->table->delete($field, $object),
        };
    }

    private function assertContext(RegisteredField $registered, ObjectRef $object): void
    {
        if ($registered->group->context !== $object->context) {
            throw InvalidFieldContext::mismatch($registered->field->id, $registered->group->context->value, $object->context->value);
        }
    }
}
