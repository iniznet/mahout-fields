<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\GroupNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidMirrorPayload;
use Iniznet\Mahout\Fields\ObjectRef;

/**
 * The read path. A registered field is read through this contract and never
 * through a raw meta call, which is the rule that makes the storage target
 * invisible at every call site.
 */
interface FieldReader
{
    /**
     * File every Table-stored row the given objects own in one statement per kind,
     * so the reads that follow cost nothing. Call it once per result set, before
     * mapping — the same duty `update_meta_cache()` discharges for the Meta target,
     * and for the same reason: without it, N objects times M fields is N×M
     * statements, and the storage target would be a performance decision at the call
     * site rather than a storage one.
     *
     * Meta-anchored and option-anchored fields need nothing here, and a set with no
     * Table-stored field registered issues no statement at all.
     *
     * @param list<ObjectRef> $objects
     */
    public function prime(array $objects): void;

    /**
     * One scalar field's value in its declared PHP shape, or null when absent.
     *
     * @throws FieldNotFound
     * @throws InvalidFieldContext when the object's context is not the field group's
     */
    public function value(string $fieldId, ObjectRef $object): string|int|float|bool|null;

    /**
     * A repeater's items in declared order, assembled from the leaves. A
     * scalar-item repeater reads as a list of scalars; a composite item reads
     * as a member-keyed record, and a nested repeater member appears in the
     * record as its own list.
     *
     * @return list<string|int|float|bool|null>|list<array<string, string|int|float|bool|list<mixed>|null>>
     *
     * @throws FieldNotFound
     * @throws InvalidFieldContext
     */
    public function items(string $fieldId, ObjectRef $object): array;

    /**
     * The expected-state hash an editor form carries for one group: the
     * revision mirror's hash, or the empty row set's hash before the first
     * guarded save. The write compares it inside the transaction; a mismatch
     * is a concurrent edit lost.
     *
     * @throws GroupNotFound        when no registered group carries the id
     * @throws InvalidFieldContext  when the object's context is not the group's
     * @throws InvalidMirrorPayload when the stored mirror is broken
     */
    public function hash(string $groupId, ObjectRef $object): string;
}
