<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\GroupNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidMirrorPayload;
use Iniznet\Mahout\Fields\Exception\InvalidRepeaterPayload;
use Iniznet\Mahout\Fields\ObjectRef;

/**
 * The read path. A registered field is read through this contract and never
 * through a raw meta call, which is the rule that makes the storage target
 * invisible at every call site.
 */
interface FieldReader
{
    /**
     * One scalar field's value in its declared PHP shape, or null when absent.
     *
     * @throws FieldNotFound
     * @throws InvalidFieldContext when the object's context is not the field group's
     */
    public function value(string $fieldId, ObjectRef $object): string|int|float|bool|null;

    /**
     * A repeater's items in declared order. On the Meta target this decodes
     * the versioned payload; on the Table target it reads the items table.
     *
     * @return list<string|int|float|bool|null>|list<array<string, string|int|float|bool>>
     *
     * @throws FieldNotFound
     * @throws InvalidFieldContext
     * @throws InvalidRepeaterPayload
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
