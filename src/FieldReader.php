<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Contracts\FieldReader as FieldReaderContract;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Internal\MetaStorage;
use Iniznet\Mahout\Fields\Internal\TableStorage;

/**
 * The read path. Every registered field is read through this service, which
 * is the whole of the encapsulation rule: the storage target is invisible at
 * the call site, so changing a field from Meta to Table is one word plus a
 * migration, with zero call-site changes.
 *
 * The value filter fires twice -- the generic form and the per-field variant,
 * whose name is constructed here and nowhere else.
 */
final readonly class FieldReader implements FieldReaderContract
{
    public function __construct(
        private readonly FieldRegistry $registry,
        private readonly MetaStorage $meta,
        private readonly TableStorage $table,
    ) {
    }

    public function value(string $fieldId, ObjectRef $object): string|int|float|bool|null
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);
        $field = $registered->field;

        if ($field instanceof RepeaterField) {
            // A Meta repeater's stored value is the versioned payload; a
            // Table repeater has no value row at all.
            if (StorageTarget::Table === $registered->storage) {
                throw InvalidFieldWrite::jsonIntoItemsTable($field->id);
            }
        }

        $raw = match ($registered->storage) {
            StorageTarget::Meta => $this->meta->read($field, $object),
            StorageTarget::Table => $this->table->read($field, $object),
        };

        $value = $field->cast($raw);

        // The filter is a trust boundary; the check below is the boundary
        // itself, and a wrong shape is a loud refusal, never a coercion.
        $filtered = \apply_filters(Hooks::VALUE, $value, $fieldId, $object->context, $object->id);

        if (null !== $filtered && !\is_scalar($filtered)) {
            throw Exception\InvalidFilterResult::notASanitisedScalar(Hooks::VALUE);
        }

        $perField = \apply_filters(Hooks::perFieldValue($fieldId), $filtered, $field, $object->id);

        if (null !== $perField && !\is_scalar($perField)) {
            throw Exception\InvalidFilterResult::notASanitisedScalar(Hooks::perFieldValue($fieldId));
        }

        return $perField;
    }

    public function items(string $fieldId, ObjectRef $object): array
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);

        $field = $registered->field;

        if (!$field instanceof RepeaterField) {
            throw InvalidFieldWrite::itemsIntoScalar($fieldId);
        }

        return match ($registered->storage) {
            StorageTarget::Meta => $this->metaItems($field, $object),
            StorageTarget::Table => $this->tableItems($field, $object),
        };
    }

    /**
     * A record item is a repeater whose items carry more than one value, stored
     * as the versioned envelope's records; the generic items table cannot hold
     * it, which the storage contract states and the registry refuses.
     *
     * @return list<string|int|float|bool|null>|list<array<string, string|int|float|bool>>
     */
    private function metaItems(RepeaterField $field, ObjectRef $object): array
    {
        $payload = $this->meta->read($field, $object);

        if (null === $payload || '' === $payload) {
            return [];
        }

        if (!\is_string($payload)) {
            throw Exception\InvalidRepeaterPayload::notAnEnvelope();
        }

        return RepeaterCodec::decode($payload);
    }

    /**
     * @return list<string|int|float|bool|null>
     */
    private function tableItems(RepeaterField $field, ObjectRef $object): array
    {
        return \array_map(
            static fn (array $item): string|int|float|bool|null => $item['value'],
            $this->table->readItems($field, $object),
        );
    }

    private function assertContext(RegisteredField $registered, ObjectRef $object): void
    {
        if ($registered->group->context !== $object->context) {
            throw InvalidFieldContext::mismatch($registered->field->id, $registered->group->context->value, $object->context->value);
        }
    }
}
