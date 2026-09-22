<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Fields\Contracts\FieldWriter as FieldWriterContract;
use Iniznet\Mahout\Fields\Exception\ConcurrentEditLost;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
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

        // The filter is a trust boundary; the check is the boundary itself.
        $sanitised = \apply_filters(Hooks::SANITIZED_VALUE, $field->sanitise($value), $field, $object->id);

        if (null !== $sanitised && !\is_scalar($sanitised)) {
            throw Exception\InvalidFilterResult::notASanitisedScalar(Hooks::SANITIZED_VALUE);
        }

        $this->withMirror($registered, $object, function () use ($registered, $object, $sanitised): void {
            $this->storeScalar($registered, $object, $sanitised);
        });

        \do_action(Hooks::AFTER_SAVE, $fieldId, $value, $field, $object->id);
    }

    /**
     * @param list<string|int|float|bool> $items
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

        \do_action(Hooks::BEFORE_SAVE, $fieldId, $items, $field, $object->id);

        $this->withMirror($registered, $object, function () use ($registered, $object, $items): void {
            $this->storeItemList($registered, $object, $items);
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
     * @param array<string, string|int|float|bool|list<string|int|float|bool>|null> $values
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

        return $this->storeGroup($group, $object, $values, $expectedHash);
    }

    /**
     * One transaction for the whole group: the guard first, then every field,
     * then the mirror. A group that binds no mirror (a non-post context, or a
     * group with no Table-bound field) writes without one, and its hash is
     * the empty row set's.
     *
     * @param array<string, string|int|float|bool|list<string|int|float|bool>|null> $values
     */
    private function storeGroup(FieldGroup $group, ObjectRef $object, array $values, string $expectedHash): string
    {
        $mirrorGroup = $this->mirrorGroup($group, $object);
        $newHash = MirrorCodec::hash([]);

        $store = function () use ($group, $object, $values): void {
            foreach ($group->fields as $field) {
                $registered = $this->registry->resolve($field->id);
                $raw = $values[$field->id] ?? null;

                if ($field instanceof RepeaterField) {
                    if (\is_array($raw)) {
                        $this->assertItemCount($field, $raw);
                    }

                    $this->storeField($registered, $object, $raw);

                    continue;
                }

                $this->storeField($registered, $object, \is_scalar($raw) ? $raw : null);
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
     * @param string|int|float|bool|list<string|int|float|bool>|null $raw
     */
    private function storeField(RegisteredField $registered, ObjectRef $object, string|int|float|bool|array|null $raw): void
    {
        $field = $registered->field;

        if ($field instanceof RepeaterField) {
            if (null === $raw) {
                $this->remove($registered, $object);

                return;
            }

            if (!\is_array($raw)) {
                throw InvalidFieldWrite::scalarIntoRepeater($field->id);
            }

            $this->storeItemList($registered, $object, $raw);

            return;
        }

        $this->storeScalar($registered, $object, \is_scalar($raw) ? $raw : null);
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
        };
    }

    /**
     * @param list<mixed> $items
     */
    private function storeItemList(RegisteredField $registered, ObjectRef $object, array $items): void
    {
        $field = $registered->field;

        if (!$field instanceof RepeaterField) {
            throw InvalidFieldWrite::itemsIntoScalar($registered->field->id);
        }

        $sanitised = $this->sanitisedItems($field, $items);

        match ($registered->storage) {
            StorageTarget::Meta => $this->meta->write($field, $object, RepeaterCodec::encode($sanitised)),
            StorageTarget::Table => $this->table->writeItems($field, $object, $sanitised),
        };
    }

    /**
     * @param list<mixed> $items
     */
    private function assertItemCount(RepeaterField $field, array $items): void
    {
        if (null !== $field->expectedMaxItems && \count($items) > $field->expectedMaxItems) {
            throw RepeaterTooLarge::items(\count($items), $field->expectedMaxItems);
        }
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

    private function declares(FieldGroup $group, string $fieldId): bool
    {
        return array_any($group->fields, fn ($field) => $field->id === $fieldId);
    }
}
