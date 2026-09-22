<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Contracts\FieldRegistry as FieldRegistryContract;
use Iniznet\Mahout\Fields\Exception\DuplicateFieldId;
use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\GroupAlreadyRegistered;
use Iniznet\Mahout\Fields\Exception\InvalidFieldId;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;

/**
 * The declaration registry. Every cross-field rule the storage contract
 * states runs here, once, at registration, so a future construction site
 * inherits the rules by registering instead of copying checks:
 *
 * - an option-context field is Meta always;
 * - a repeater declared queried binds the items table, never JSON;
 * - a repeater whose item type has no generic items column binds a dedicated
 *   table or stores JSON;
 * - a field id answers to exactly one field, across every group.
 *
 * The mahout/fields/storage_target filter is applied per field before the
 * checks, and its result -- never the declaration alone -- is what the
 * adapters are dispatched on. That filter is what makes a meta-to-table
 * migration possible from outside the library, and it is correspondingly
 * dangerous: a wrong return is refused loudly.
 */
final class FieldRegistry implements FieldRegistryContract
{
    /** @var array<string, FieldGroup> */
    private array $groups = [];

    /** @var array<string, RegisteredField> */
    private array $fields = [];

    public function register(FieldGroup $group): void
    {
        if (isset($this->groups[$group->id])) {
            throw GroupAlreadyRegistered::forId($group->id);
        }

        if ([] === $group->fields) {
            throw InvalidFieldId::emptyGroup($group->id);
        }

        $seen = [];
        foreach ($group->fields as $field) {
            if (isset($seen[$field->id])) {
                throw DuplicateFieldId::inGroup($field->id, $group->id);
            }

            if (isset($this->fields[$field->id])) {
                throw DuplicateFieldId::acrossGroups($field->id, $group->id);
            }

            $seen[$field->id] = true;
        }

        // The resolution: the declared target through the one deliberately
        // dangerous filter, then the combination rules against the result.
        foreach ($group->fields as $field) {
            // The filter is a trust boundary; the check below is the boundary
            // itself, and a wrong shape is a loud refusal, never a coercion.
            $filtered = \apply_filters(Hooks::STORAGE_TARGET, $field->storage, $field);

            if (!$filtered instanceof StorageTarget) {
                throw Exception\InvalidFilterResult::notAStorageTarget(Hooks::STORAGE_TARGET);
            }

            $this->assertCombination($group, $field, $filtered);

            $this->fields[$field->id] = new RegisteredField($field, $group, $filtered);
        }

        $this->groups[$group->id] = $group;

        \do_action(Hooks::GROUP_REGISTERED, $group);
    }

    public function has(string $fieldId): bool
    {
        return isset($this->fields[$fieldId]);
    }

    public function resolve(string $fieldId): RegisteredField
    {
        return $this->fields[$fieldId] ?? throw FieldNotFound::forId($fieldId);
    }

    public function field(string $fieldId): Field
    {
        return $this->resolve($fieldId)->field;
    }

    /**
     * @return list<FieldGroup>
     */
    public function groups(): array
    {
        return \array_values($this->groups);
    }

    private function assertCombination(FieldGroup $group, Field $field, StorageTarget $resolved): void
    {
        if (ObjectContext::Option === $group->context && StorageTarget::Table === $resolved) {
            throw InvalidStorageCombination::optionContextTable($field->id);
        }

        if (!$field instanceof RepeaterField) {
            return;
        }

        if ($field->queried && StorageTarget::Meta === $resolved) {
            throw InvalidStorageCombination::queriedRepeaterInMeta($field->id);
        }

        if (StorageTarget::Table === $resolved) {
            if (!FieldItemsTable::holdsItem($field->item->type())) {
                throw InvalidStorageCombination::repeaterItemWithoutColumn($field->id, $field->item->type()->value);
            }
        }
    }
}
