<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\GroupAlreadyRegistered;
use Iniznet\Mahout\Fields\Exception\GroupNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidFilterResult;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\Field;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\RegisteredField;

/**
 * The declaration registry: the one place field groups are registered, and the
 * one place the storage combination rules run.
 *
 * The registry is a service and is injected, never reached statically. A group
 * registers exactly once; every cross-field rule -- duplicate ids, the option
 * context's storage refusal, the queried-repeater binding -- runs here, in
 * one place, before any value can be written.
 */
interface FieldRegistry
{
    /**
     * Register a field group.
     *
     * @throws GroupAlreadyRegistered    when the group id is taken
     * @throws InvalidStorageCombination when a field's declared (or filtered) target cannot serve its context
     * @throws InvalidFilterResult       when the storage_target filter returns anything but a StorageTarget
     */
    public function register(FieldGroup $group): void;

    public function has(string $fieldId): bool;

    /**
     * The resolved registration of one field: the field, its group and the
     * storage target it is bound to.
     *
     * @throws FieldNotFound when no registered field carries the id
     */
    public function resolve(string $fieldId): RegisteredField;

    /**
     * The declared field with this id, whatever its group.
     *
     * @throws FieldNotFound
     */
    public function field(string $fieldId): Field;

    /**
     * The declared group with this id.
     *
     * @throws GroupNotFound when no registered group carries the id
     */
    public function group(string $groupId): FieldGroup;

    /**
     * @return list<FieldGroup>
     */
    public function groups(): array;
}
