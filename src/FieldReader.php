<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Contracts\FieldReader as FieldReaderContract;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\Internal\MetaStorage;
use Iniznet\Mahout\Fields\Internal\RevisionMirror;
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
        private readonly RevisionMirror $mirror,
    ) {
    }

    public function value(string $fieldId, ObjectRef $object): string|int|float|bool|null
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);
        $field = $registered->field;

        if ($field instanceof RepeaterField) {
            // A repeater has no scalar value: its leaves are read through
            // items(), on either target.
            throw InvalidFieldWrite::scalarIntoRepeater($field->id);
        }

        $raw = match ($registered->storage) {
            StorageTarget::Meta => $this->meta->read($field, $object),
            StorageTarget::Table => $this->table->read($field, $object),
            StorageTarget::Carried => throw InvalidStorageCombination::carriedOutsideRepeater($field->id),
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

        return $this->assemble($field, $this->repeaterLeaves($field, $object, $registered->storage), $field->id);
    }

    /**
     * The leaves, from whichever target holds them, normalised to one shape.
     *
     * @return list<array{address: string, member: string, raw: string|int|float|bool|null}>
     */
    private function repeaterLeaves(RepeaterField $field, ObjectRef $object, StorageTarget $storage): array
    {
        $leaves = StorageTarget::Table === $storage
            ? $this->table->readLeaves($field, $object)
            : $this->metaLeafRows($field, $object);

        usort($leaves, static fn (array $a, array $b): int => LeafAddress::compare($a['address'], $b['address']));

        return $leaves;
    }

    /**
     * The Meta target's leaves: the object's own meta set enumerated by the
     * adapter, keyed by full address.
     *
     * @return list<array{address: string, member: string, raw: string|int|float|bool|null}>
     */
    private function metaLeafRows(RepeaterField $field, ObjectRef $object): array
    {
        $rows = [];

        foreach ($this->meta->leaves($field->id, $object) as $address => $raw) {
            $rows[] = [
                'address' => (string) $address,
                'member' => LeafAddress::memberOf((string) $address),
                'raw' => $raw,
            ];
        }

        return $rows;
    }

    /**
     * Assemble the declared shape from the leaves: positions derive from the
     * addresses, a scalar item is its one leaf, a composite item is its
     * members' leaves, and a nested repeater member recurses. Absent members
     * read null; the structure, not the storage, carries the nesting.
     *
     * @param list<array{address: string, member: string, raw: string|int|float|bool|null}> $leaves
     *
     * @return list<string|int|float|bool|null>|list<array<string, string|int|float|bool|list<mixed>|null>>
     */
    private function assemble(RepeaterField $field, array $leaves, string $prefix): array
    {
        $positions = [];

        foreach ($leaves as $leaf) {
            if (!str_starts_with($leaf['address'], $prefix.'.')) {
                continue;
            }

            $rest = substr($leaf['address'], strlen($prefix) + 1);
            $position = (int) strtok($rest, '.');
            $positions[$position] = true;
        }

        $positions = array_keys($positions);
        sort($positions);

        $items = [];

        foreach ($positions as $position) {
            $base = $prefix.'.'.$position;
            $items[] = $field->item instanceof Field
                ? $this->leafValue($field->item, $this->leafAt($leaves, $base))
                : $this->compositeItem($field, $leaves, $base);
        }

        return $items;
    }

    /**
     * @param list<array{address: string, member: string, raw: string|int|float|bool|null}> $leaves
     *
     * @return array<string, string|int|float|bool|list<mixed>|null>
     */
    private function compositeItem(RepeaterField $field, array $leaves, string $base): array
    {
        $item = [];

        foreach ($field->members() as $member) {
            if ($member instanceof RepeaterField) {
                $item[$member->id] = $this->assemble($member, $leaves, $base.'.'.$member->id);

                continue;
            }

            $item[$member->id] = $this->leafValue($member, $this->leafAt($leaves, $base.'.'.$member->id));
        }

        return $item;
    }

    /**
     * @param list<array{address: string, member: string, raw: string|int|float|bool|null}> $leaves
     */
    private function leafAt(array $leaves, string $address): string|int|float|bool|null
    {
        foreach ($leaves as $leaf) {
            if ($leaf['address'] === $address) {
                return $leaf['raw'];
            }
        }

        return null;
    }

    private function leafValue(Field $member, string|int|float|bool|null $raw): string|int|float|bool|null
    {
        return $member->cast($raw);
    }

    public function hash(string $groupId, ObjectRef $object): string
    {
        $group = $this->registry->group($groupId);

        if ($group->context !== $object->context) {
            throw InvalidFieldContext::mismatch($groupId, $group->context->value, $object->context->value);
        }

        // The hash is read back from the mirror, never recomputed from the
        // table: the mirror is the reference the form was rendered against,
        // and an out-of-band table edit is caught rather than blessed.
        return $this->mirror->currentHash($object, $groupId);
    }

    private function assertContext(RegisteredField $registered, ObjectRef $object): void
    {
        if ($registered->group->context !== $object->context) {
            throw InvalidFieldContext::mismatch($registered->field->id, $registered->group->context->value, $object->context->value);
        }
    }
}
