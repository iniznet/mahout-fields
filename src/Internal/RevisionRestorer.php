<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Exception\InvalidMirrorPayload;
use Iniznet\Mahout\Fields\Field;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\LeafAddress;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;

/**
 * Rehydrates a group's table rows from the revision mirror, on core's own
 * restore path.
 *
 * Core copies revisioned meta into the restored post at priority 10 on
 * wp_restore_post_revision; this rehydrator runs at 20, reads the post's
 * already-restored mirror, and rewrites the table rows in one transaction, so
 * the table and the mirror agree again. Core's mechanism does the copying;
 * this class only turns the restored payload back into rows.
 *
 * A mirror that is absent after the restore means the restored state holds no
 * values for the group, so the group's table rows are removed: the table and
 * the mirror agree in both directions. A payload field the group no longer
 * declares is a loud refusal -- the mirror is newer than the code.
 *
 * @internal
 */
final readonly class RevisionRestorer
{
    public function __construct(
        private readonly FieldRegistry $registry,
        private readonly TableGateway $gateway,
        private readonly TableStorage $table,
        private readonly RevisionMirror $mirror,
    ) {
    }

    /**
     * The payload is read from the restored post, which core's own meta
     * restore at priority 10 has already written.
     */
    public function restore(int $postId): void
    {
        $object = ObjectRef::post($postId);

        foreach ($this->registry->groups() as $group) {
            if (!$this->mirrors($group)) {
                continue;
            }

            $this->rehydrate($group, $object);
        }
    }

    private function mirrors(FieldGroup $group): bool
    {
        if (ObjectContext::Post !== $group->context) {
            return false;
        }

        return array_any($group->fields, fn ($field) => StorageTarget::Table === $this->registry->resolve($field->id)->storage);
    }

    private function rehydrate(FieldGroup $group, ObjectRef $object): void
    {
        $payload = $this->mirror->payloadOf($object, $group->id);

        if (null === $payload) {
            $this->removeAll($group, $object);

            return;
        }

        $rows = $this->rowsByField($group, $payload['rows']);

        $this->gateway->transactional(function () use ($group, $object, $rows): void {
            foreach ($group->fields as $field) {
                $row = $rows[$field->id] ?? null;

                $this->writeRow($field, $object, \is_array($row) ? $row : null);
            }
        });
    }

    /**
     * @param list<array{field: string, value?: string|int|float|bool, items?: list<string|int|float|bool>}> $payloadRows
     *
     * @return array<string, array{value?: string|int|float|bool, items?: list<string|int|float|bool>}>
     *
     * @throws InvalidMirrorPayload
     */
    private function rowsByField(FieldGroup $group, array $payloadRows): array
    {
        $rows = [];
        foreach ($payloadRows as $row) {
            $fieldId = $row['field'];

            if (!$this->declares($group, $fieldId)) {
                throw InvalidMirrorPayload::unknownField($group->id, $fieldId);
            }

            $rows[$fieldId] = $row;
        }

        return $rows;
    }

    private function declares(FieldGroup $group, string $fieldId): bool
    {
        return array_any($group->fields, fn ($field) => $field->id === $fieldId);
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @throws InvalidMirrorPayload
     */
    private function writeRow(Field $field, ObjectRef $object, ?array $row): void
    {
        if (null === $row) {
            $this->removeRow($field, $object);

            return;
        }

        // The shape was validated once at decode; the restorer trusts it.
        if ($field instanceof RepeaterField) {
            $leaves = \is_array($row['leaves'] ?? null) ? $row['leaves'] : [];

            $this->table->writeLeaves($field, $object, $this->restoredLeaves($field, $leaves));

            return;
        }

        $value = $row['value'] ?? null;

        if (!\is_scalar($value)) {
            throw InvalidMirrorPayload::malformedRow($field->id, 0);
        }

        $this->table->write($field, $object, $value);
    }

    /**
     * The restored leaf set, each leaf's member field resolved against the
     * declaration the address walks. The member field     /**
     * The restored leaf set, each leaf's member field resolved against the
     * declaration the address walks. The member field carries the leaf's
     * column and canonicalisation; the address carries the ancestry. The
     * leaves arrive from the decoded mirror and are shaped here, loudly.
     *
     * @param array<mixed, mixed> $restored
     *
     * @return list<array{address: string, relative: string, member: string, field: Field, value: string|int|float|bool}>
     */
    private function restoredLeaves(RepeaterField $root, array $restored): array
    {
        $leaves = [];

        foreach ($restored as $index => $leaf) {
            if (!\is_array($leaf) || !\is_string($leaf['address'] ?? null) || !\is_scalar($leaf['value'] ?? null)) {
                throw InvalidMirrorPayload::malformedRow($root->id, (int) $index);
            }

            $address = $root->id.'.'.$leaf['address'];
            $parsed = LeafAddress::of($address);
            $member = $this->memberFieldAt($root, $address);

            $leaves[] = [
                'address' => $address,
                'relative' => $parsed->relative,
                'member' => $parsed->member,
                'field' => $member,
                'value' => $leaf['value'],
            ];
        }

        return $leaves;
    }

    private function memberFieldAt(RepeaterField $root, string $fullAddress): Field
    {
        $address = LeafAddress::of($fullAddress);
        $current = $root;
        $segments = explode('.', $address->relative);
        $index = 0;

        while (true) {
            if (!isset($segments[$index]) || !ctype_digit($segments[$index])) {
                throw InvalidMirrorPayload::malformedRow($root->id, 0);
            }

            ++$index;

            if (!isset($segments[$index])) {
                if (!$current->item instanceof Field) {
                    throw InvalidMirrorPayload::malformedRow($root->id, 0);
                }

                return $current->item;
            }

            $member = null;

            foreach ($current->members() as $candidate) {
                if ($candidate->id === $segments[$index]) {
                    $member = $candidate;

                    break;
                }
            }

            if (null === $member) {
                throw InvalidMirrorPayload::malformedRow($root->id, 0);
            }

            ++$index;

            if (!$member instanceof RepeaterField) {
                if (isset($segments[$index])) {
                    throw InvalidMirrorPayload::malformedRow($root->id, 0);
                }

                return $member;
            }

            $current = $member;
        }
    }

    private function removeRow(Field $field, ObjectRef $object): void
    {
        if ($field instanceof RepeaterField) {
            $this->table->deleteLeaves($field, $object);

            return;
        }

        if (StorageTarget::Table === $this->registry->resolve($field->id)->storage) {
            $this->table->delete($field, $object);
        }
    }

    private function removeAll(FieldGroup $group, ObjectRef $object): void
    {
        $this->gateway->transactional(function () use ($group, $object): void {
            foreach ($group->fields as $field) {
                $this->removeRow($field, $object);
            }
        });
    }
}
