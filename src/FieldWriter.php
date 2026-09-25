<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Fields\Contracts\FieldWriter as FieldWriterContract;
use Iniznet\Mahout\Fields\Exception\ConcurrentEditLost;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;
use Iniznet\Mahout\Fields\Internal\GroupSnapshot;
use Iniznet\Mahout\Fields\Internal\RevisionMirror;

/**
 * The write path. Sanitisation happens exactly once, here, through the
 * field's own sanitiser; the adapters canonicalise and store.
 *
 * The lifecycle seam: before_save fires before sanitisation, sanitized_value
 * between sanitisation and storage, after_save after the adapter returned.
 * The save lifecycle's guards -- capability, nonce, autosave, revision, post
 * lock -- arrive in a later slice and wrap these calls.
 *
 * Every write that touches a Table-bound field joins the group's revision
 * mirror into the same transaction, because a committed row set with a stale
 * mirror loses the field on the next revision restore. writeGroup() is the
 * store step of the save lifecycle: the whole group is one transaction, the
 * lost-update comparison happens inside it before the first write, and the
 * mirror is written last -- so a forced mirror failure leaves the tables
 * unchanged, and nothing is partially applied, substituted or retried.
 */
final readonly class FieldWriter implements FieldWriterContract
{
    public function __construct(
        private readonly FieldRegistry $registry,
        private readonly Internal\MetaStorage $meta,
        private readonly Internal\TableStorage $table,
        private readonly TableGateway $gateway,
        private readonly RevisionMirror $mirror,
        private readonly GroupSnapshot $snapshot,
    ) {
    }

    public function set(string $fieldId, ObjectRef $object, string|int|float|bool|null $value): void
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);
        $field = $registered->field;

        \do_action(Hooks::BEFORE_SAVE, $fieldId, $value, $field, $object->id);

        $sanitised = $this->sanitisedScalar($field, $value, $object->id);

        $this->withMirror($registered, $object, function () use ($registered, $object, $sanitised): void {
            $this->storeScalar($registered, $object, $sanitised);
        });

        \do_action(Hooks::AFTER_SAVE, $fieldId, $value, $field, $object->id);
    }

    /**
     * @param array<mixed, mixed> $items
     */
    public function setItems(string $fieldId, ObjectRef $object, array $items): void
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);

        $field = $registered->field;

        if (!$field instanceof RepeaterField) {
            throw InvalidFieldWrite::itemsIntoScalar($fieldId);
        }

        $this->assertItemCount($field, $items);

        $sanitised = $this->sanitisedItems($field, $items, $field->id);

        \do_action(Hooks::BEFORE_SAVE, $fieldId, $items, $field, $object->id);

        $this->withMirror($registered, $object, function () use ($registered, $object, $sanitised): void {
            $this->storeSanitisedItems($registered, $object, $sanitised);
        });

        \do_action(Hooks::AFTER_SAVE, $fieldId, $items, $field, $object->id);
    }

    public function delete(string $fieldId, ObjectRef $object): void
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);

        \do_action(Hooks::BEFORE_DELETE, $fieldId, $registered->field, $object->id);

        $this->withMirror($registered, $object, function () use ($registered, $object): void {
            $this->remove($registered, $object);
        });

        \do_action(Hooks::AFTER_DELETE, $fieldId, $registered->field, $object->id);
    }

    /**
     * @param array<string, string|int|float|bool|list<array{address: string, relative: string, member: string, field: Field, value: string|int|float|bool}>|null> $values
     */
    public function writeGroup(string $groupId, ObjectRef $object, array $values, string $expectedHash): string
    {
        $group = $this->registry->group($groupId);

        if ($group->context !== $object->context) {
            throw InvalidFieldContext::mismatch($groupId, $group->context->value, $object->context->value);
        }

        // A submitted id the group does not declare is a mass-assignment
        // attempt or a stale form; both are refusals, decided before the
        // first statement runs.
        foreach (\array_keys($values) as $fieldId) {
            if (!$this->declares($group, (string) $fieldId)) {
                throw Exception\FieldNotFound::inGroup((string) $fieldId, $groupId);
            }
        }

        foreach ($values as $fieldId => $value) {
            $field = $this->registry->field((string) $fieldId);

            if ($field instanceof RepeaterField) {
                if (null !== $value && !\is_array($value)) {
                    throw InvalidFieldWrite::scalarIntoRepeater($field->id);
                }
            } elseif (\is_array($value)) {
                throw InvalidFieldWrite::itemsIntoScalar($field->id);
            }
        }

        // Guard 8 of the save lifecycle: sanitise exactly once, before the
        // transaction opens, so a refused value never reaches the store step
        // and the sanitised_value filter never fires inside a transaction.
        $sanitised = [];

        foreach ($values as $fieldId => $value) {
            $field = $this->registry->field((string) $fieldId);

            if ($field instanceof RepeaterField) {
                if (\is_array($value)) {
                    $sanitised[$fieldId] = $this->sanitisedItems($field, $value, $field->id);
                } else {
                    $sanitised[$fieldId] = null;
                }

                continue;
            }

            $sanitised[$fieldId] = $this->sanitisedScalar($field, $this->storableScalar($field->id, $value), $object->id);
        }

        return $this->storeGroup($group, $object, $sanitised, $expectedHash);
    }

    /**
     * One transaction for the whole group: the guard first, then every field,
     * then the mirror. A group that binds no mirror (a non-post context, or a
     * group with no Table-bound field) writes without one, and its hash is
     * the empty row set's.
     *
     * @param array<string, string|int|float|bool|list<array{address: string, relative: string, member: string, field: Field, value: string|int|float|bool}>|null> $values
     */
    private function storeGroup(FieldGroup $group, ObjectRef $object, array $values, string $expectedHash): string
    {
        $mirrorGroup = $this->mirrorGroup($group, $object);
        $newHash = MirrorCodec::hash([]);

        // The values arrive sanitised: sanitisation is guard 8, the store step
        // is guard 9, and the order is the lifecycle's.
        $store = function () use ($group, $object, $values): void {
            foreach ($group->fields as $field) {
                $registered = $this->registry->resolve($field->id);
                $value = $values[$field->id] ?? null;

                if ($field instanceof RepeaterField) {
                    $this->storeSanitisedItems($registered, $object, \is_array($value) ? $value : null);

                    continue;
                }

                $this->storeScalar($registered, $object, $this->storableScalar($registered->field->id, $value));
            }
        };

        if (null === $mirrorGroup) {
            $store();

            return $newHash;
        }

        $this->gateway->transactional(function () use ($mirrorGroup, $object, $expectedHash, $store, &$newHash): void {
            // The guard is a read performed after START TRANSACTION and
            // before any write. Comparing outside the transaction is a race
            // with a shorter window and an invisible difference.
            $current = $this->mirror->currentHash($object, $mirrorGroup->id);

            if (!hash_equals($current, $expectedHash)) {
                throw ConcurrentEditLost::forGroup($mirrorGroup->id, $object->id);
            }

            $store();

            $newHash = $this->mirror->write($object, $mirrorGroup->id, $this->snapshot->rows($mirrorGroup, $object));
        });

        return $newHash;
    }

    public function writeField(string $fieldId, ObjectRef $object, string|int|float|bool|array|null $value, string $expectedHash): string
    {
        $registered = $this->registry->resolve($fieldId);
        $this->assertContext($registered, $object);

        $field = $registered->field;

        if (StorageTarget::Table !== $registered->storage) {
            throw InvalidFieldWrite::routeWritesMeta($fieldId);
        }

        \do_action(Hooks::BEFORE_SAVE, $fieldId, $value, $field, $object->id);

        // Sanitisation runs once, before the transaction opens, and each
        // arm's product keeps its own name: the closure's store step reads
        // the one its field's resolved type names, so no coercion sits
        // between the sanitised value and the store.
        $sanitisedItems = $field instanceof RepeaterField && \is_array($value)
            ? $this->sanitisedItems($field, $value, $field->id)
            : null;
        $sanitisedScalar = !$field instanceof RepeaterField
            ? $this->sanitisedScalar($field, $this->storableScalar($fieldId, $value), $object->id)
            : null;

        $group = $registered->group;
        $newHash = '';

        $this->gateway->transactional(function () use ($registered, $group, $object, $sanitisedItems, $sanitisedScalar, $expectedHash, &$newHash): void {
            // The lost-update guard is a read inside the transaction, before
            // any write -- the same guard writeGroup() carries, for the one
            // field the route writes.
            $current = $this->mirror->currentHash($object, $group->id);

            if (!hash_equals($current, $expectedHash)) {
                throw ConcurrentEditLost::forGroup($group->id, $object->id);
            }

            if ($registered->field instanceof RepeaterField) {
                $this->storeSanitisedItems($registered, $object, $sanitisedItems);
            } else {
                $this->storeScalar($registered, $object, $sanitisedScalar);
            }

            $newHash = $this->mirror->write($object, $group->id, $this->snapshot->rows($group, $object));
        });

        \do_action(Hooks::AFTER_SAVE, $fieldId, $value, $field, $object->id);

        return $newHash;
    }

    /**
     * One field's store with the group's mirror joined to it. The mirror
     * records the group's whole row set read from the adapters inside the
     * transaction, so it always matches what the tables hold.
     */
    private function withMirror(RegisteredField $registered, ObjectRef $object, \Closure $store): void
    {
        $group = $this->mirrorFor($registered, $object);

        if (null === $group) {
            $store();

            return;
        }

        $this->gateway->transactional(function () use ($store, $group, $object): void {
            $store();

            $this->mirror->write($object, $group->id, $this->snapshot->rows($group, $object));
        });
    }

    /**
     * A single-field write syncs the mirror only when THIS field is
     * Table-bound: the mirror records the group's table rows, and a Meta-bound
     * field changes none of them.
     */
    private function mirrorFor(RegisteredField $registered, ObjectRef $object): ?FieldGroup
    {
        if (ObjectContext::Post !== $object->context || StorageTarget::Table !== $registered->storage) {
            return null;
        }

        return $registered->group;
    }

    /**
     * A mirror exists for a post-context group with at least one Table-bound
     * field. Revisions are post-only, and a group that binds no table row has
     * nothing for a mirror to hold.
     */
    private function mirrorGroup(FieldGroup $group, ObjectRef $object): ?FieldGroup
    {
        if (ObjectContext::Post !== $object->context) {
            return null;
        }

        foreach ($group->fields as $field) {
            if (StorageTarget::Table === $this->registry->resolve($field->id)->storage) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The leaves arrive sanitised — sanitisation happened once, in the
     * caller's guard step — so this is the store shape only. Null removes.
     *
     * @param list<array{address: string, relative: string, member: string, field: Field, value: string|int|float|bool}>|null $leaves
     */
    private function storeSanitisedItems(RegisteredField $registered, ObjectRef $object, ?array $leaves): void
    {
        $field = $registered->field;

        if (!$field instanceof RepeaterField) {
            throw InvalidFieldWrite::itemsIntoScalar($registered->field->id);
        }

        if (null === $leaves || [] === $leaves) {
            $this->remove($registered, $object);

            return;
        }

        match ($registered->storage) {
            StorageTarget::Meta => $this->storeMetaLeaves($field, $object, $leaves),
            StorageTarget::Table => $this->table->writeLeaves($field, $object, $leaves),
            StorageTarget::Carried => throw InvalidStorageCombination::carriedOutsideRepeater($field->id),
        };
    }

    /**
     * The Meta target's replace: the stored leaves under the root go first,
     * by exact key — the enumeration, never a LIKE query — then the current
     * leaves land.
     *
     * @param list<array{address: string, relative: string, member: string, field: Field, value: string|int|float|bool}> $leaves
     */
    private function storeMetaLeaves(RepeaterField $field, ObjectRef $object, array $leaves): void
    {
        foreach (array_keys($this->meta->leaves($field->id, $object)) as $stale) {
            $this->meta->deleteLeaf((string) $stale, $object);
        }

        foreach ($leaves as $leaf) {
            $this->meta->writeLeaf($leaf['address'], $object, $leaf['value']);
        }
    }

    private function dropMetaLeaves(RepeaterField $field, ObjectRef $object): void
    {
        foreach (array_keys($this->meta->leaves($field->id, $object)) as $stale) {
            $this->meta->deleteLeaf((string) $stale, $object);
        }
    }

    /**
     * One field's own sanitiser, then the trust boundary the filter is. Fired
     * before the transaction opens, so a wrong filter result never runs inside
     * one, and exactly one sanitisation exists per value.
     */
    private function sanitisedScalar(Field $field, string|int|float|bool|null $raw, int $objectId): string|int|float|bool|null
    {
        $sanitised = \apply_filters(Hooks::SANITIZED_VALUE, $field->sanitise($raw), $field, $objectId);

        if (null !== $sanitised && !\is_scalar($sanitised)) {
            throw Exception\InvalidFilterResult::notASanitisedScalar(Hooks::SANITIZED_VALUE);
        }

        return $sanitised;
    }

    /**
     * The store step accepts exactly a scalar or the null that deletes. A
     * value of any other shape reaching this point is a broken contract
     * upstream of sanitisation, and is refused rather than coerced into a
     * deletion. The value arrives as the caller sent it — the accepted union
     * is the contracts' promise, not a guarantee this boundary can type, so
     * the refusal is this method's whole purpose and the parameter carries
     * no narrower type than the call site can prove.
     *
     * @param mixed $value the submitted value, unsanitised or sanitised
     */
    private function storableScalar(string $fieldId, mixed $value): string|int|float|bool|null
    {
        if (\is_scalar($value) || null === $value) {
            return $value;
        }

        throw InvalidFieldWrite::unstorableValue($fieldId);
    }

    private function storeScalar(RegisteredField $registered, ObjectRef $object, string|int|float|bool|null $sanitised): void
    {
        if (null === $sanitised) {
            $this->remove($registered, $object);

            return;
        }

        match ($registered->storage) {
            StorageTarget::Meta => $this->meta->write($registered->field, $object, $sanitised),
            StorageTarget::Table => $this->table->write($registered->field, $object, $sanitised),
            StorageTarget::Carried => throw InvalidStorageCombination::carriedOutsideRepeater($registered->field->id),
        };
    }

    /**
     * @param array<mixed, mixed> $items
     */
    private function assertItemCount(RepeaterField $field, array $items): void
    {
        if (null !== $field->expectedMaxItems && \count($items) > $field->expectedMaxItems) {
            throw RepeaterTooLarge::items(\count($items), $field->expectedMaxItems);
        }
    }

    /**
     * Flatten the submitted item shape into sanitised leaves, one per scalar
     * the structure carries. The shape mirrors the declaration: a scalar-item
     * repeater takes a list of scalars, a composite one takes a list of
     * member-keyed arrays, a nested repeater member takes a list — anything
     * else is a loud refusal before the first statement runs. A null leaf is
     * absence, not a stored empty value.
     *
     * @param array<mixed, mixed> $items
     *
     * @return list<array{address: string, relative: string, member: string, field: Field, value: string|int|float|bool}>
     */
    private function sanitisedItems(RepeaterField $field, array $items, string $prefix): array
    {
        $this->assertItemCount($field, $items);

        $leaves = [];
        $scalarItem = $field->item instanceof Field;

        foreach ($items as $position => $item) {
            if (!\ctype_digit((string) $position)) {
                throw InvalidFieldWrite::badRepeaterAddress($field->id);
            }

            $base = $prefix.'.'.$position;

            if ($scalarItem) {
                if (null === $item) {
                    continue;
                }

                if (!\is_scalar($item)) {
                    throw InvalidFieldWrite::badRepeaterAddress($field->id);
                }

                $leaves[] = $this->leaf($field, $base, '', $field->item, $item);

                continue;
            }

            if (!\is_array($item)) {
                throw InvalidFieldWrite::badRepeaterAddress($field->id);
            }

            foreach ($field->members() as $member) {
                $value = $item[$member->id] ?? null;
                $address = $base.'.'.$member->id;

                if ($member instanceof RepeaterField) {
                    if (null === $value) {
                        continue;
                    }

                    if (!\is_array($value)) {
                        throw InvalidFieldWrite::badRepeaterAddress($field->id);
                    }

                    $leaves = [...$leaves, ...$this->sanitisedItems($member, $value, $address)];

                    continue;
                }

                if (null === $value) {
                    continue;
                }

                if (!\is_scalar($value)) {
                    throw InvalidFieldWrite::badRepeaterAddress($field->id);
                }

                $leaves[] = $this->leaf($field, $address, $member->id, $member, $value);
            }
        }

        return $leaves;
    }

    /**
     * One sanitised leaf: the address is validated against the grammar and
     * the byte cap here, at the one moment it is assembled, and the member's
     * own sanitiser runs exactly once.
     *
     * @return array{address: string, relative: string, member: string, field: Field, value: string|int|float|bool}
     */
    private function leaf(RepeaterField $root, string $address, string $member, Field $memberField, string|int|float|bool $value): array
    {
        LeafAddress::of($address);

        $sanitised = $memberField->sanitise($value);

        if (null === $sanitised) {
            throw InvalidFieldValue::refused($root->id, 'repeater leaf', $address, 'the leaf sanitised to nothing');
        }

        return [
            'address' => $address,
            'relative' => LeafAddress::of($address)->relative,
            'member' => $member,
            'field' => $memberField,
            'value' => $sanitised,
        ];
    }

    private function remove(RegisteredField $registered, ObjectRef $object): void
    {
        $field = $registered->field;

        if ($field instanceof RepeaterField) {
            match ($registered->storage) {
                StorageTarget::Meta => $this->dropMetaLeaves($field, $object),
                StorageTarget::Table => $this->table->deleteLeaves($field, $object),
                StorageTarget::Carried => throw InvalidStorageCombination::carriedOutsideRepeater($field->id),
            };

            return;
        }

        match ($registered->storage) {
            StorageTarget::Meta => $this->meta->delete($field, $object),
            StorageTarget::Table => $this->table->delete($field, $object),
            StorageTarget::Carried => throw InvalidStorageCombination::carriedOutsideRepeater($field->id),
        };
    }

    private function assertContext(RegisteredField $registered, ObjectRef $object): void
    {
        if ($registered->group->context !== $object->context) {
            throw InvalidFieldContext::mismatch($registered->field->id, $registered->group->context->value, $object->context->value);
        }
    }

    private function declares(FieldGroup $group, string $fieldId): bool
    {
        return array_any($group->fields, fn ($field) => $field->id === $fieldId);
    }
}
