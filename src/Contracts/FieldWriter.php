<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Exception\ConcurrentEditLost;
use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\GroupNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\InvalidMirrorPayload;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;
use Iniznet\Mahout\Fields\ObjectRef;

/**
 * The write path. Sanitisation happens here -- exactly once -- and the
 * storage adapters store what the field sanitised.
 *
 * The save lifecycle's guards (capability, nonce, autosave, revision, post
 * lock, lost-update hash) belong to the save lifecycle that wraps this
 * contract; they arrive in a later slice and wrap these calls, never replace
 * them.
 */
interface FieldWriter
{
    /**
     * Store one scalar field's value. A null means absent: the stored value is
     * removed rather than replaced with an empty placeholder.
     *
     * @throws FieldNotFound
     * @throws InvalidFieldValue
     * @throws InvalidFieldContext
     */
    public function set(string $fieldId, ObjectRef $object, string|int|float|bool|null $value): void;

    /**
     * Store a repeater's items, replacing whatever the field held.
     *
     * @param list<string|int|float|bool> $items the raw item values
     *
     * @throws FieldNotFound
     * @throws InvalidFieldWrite
     * @throws RepeaterTooLarge
     * @throws InvalidFieldContext
     */
    public function setItems(string $fieldId, ObjectRef $object, array $items): void;

    /**
     * @throws FieldNotFound
     * @throws InvalidFieldContext
     */
    public function delete(string $fieldId, ObjectRef $object): void;

    /**
     * The store step of one group's save: every field of the group is one
     * transaction together with the group's revision mirror, and the
     * lost-update comparison happens inside that transaction, before the
     * first write. A stale expected hash raises ConcurrentEditLost and
     * writes nothing; a matching one writes and returns the hash the mirror
     * now carries.
     *
     * An absent key removes that field's value; a submitted id the group
     * does not declare is a mass-assignment attempt and is refused.
     *
     * @param array<string, string|int|float|bool|list<string|int|float|bool>|null> $values field id to raw value, or raw item list for a repeater
     *
     * @return string the mirror hash after the write
     *
     * @throws ConcurrentEditLost   when the expected hash does not match
     * @throws FieldNotFound        when a submitted id is not declared
     * @throws GroupNotFound        when no registered group carries the id
     * @throws InvalidFieldContext  when the object's context is not the group's
     * @throws InvalidFieldValue    for a refused value
     * @throws InvalidFieldWrite    for a shape mismatch
     * @throws InvalidMirrorPayload when the stored mirror is broken
     * @throws RepeaterTooLarge     above the declared cap
     */
    public function writeGroup(string $groupId, ObjectRef $object, array $values, string $expectedHash): string;

    /**
     * The REST route's single-field write: sanitise once, then one transaction
     * whose first read is the group's lost-update guard and whose last write is
     * the mirror. A field that is not Table-bound is refused: the route exists
     * because Table storage has no other write path, and a Meta field is
     * written through register_post_meta().
     *
     * @param string|int|float|bool|list<string|int|float|bool>|null $value the raw value, or raw item list for a repeater
     *
     * @return string the mirror hash after the write
     *
     * @throws ConcurrentEditLost  when the expected hash does not match
     * @throws FieldNotFound       when the id is not registered
     * @throws InvalidFieldContext when the object's context is not the group's
     * @throws InvalidFieldWrite   when the field is not Table-bound or the shape is wrong
     */
    public function writeField(string $fieldId, ObjectRef $object, string|int|float|bool|array|null $value, string $expectedHash): string;
}
