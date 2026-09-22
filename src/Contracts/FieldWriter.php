<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
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
}
