<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;

/**
 * A repeater: repeated items at explicit positions, the one hierarchical
 * field type.
 *
 * The item is either one scalar field (the scalar convenience, unchanged call
 * shape) or a RepeaterItem carrying several. A member may itself be a
 * RepeaterField — nesting is legal, capped at {@see MAX_DEPTH} levels, and
 * every member declares StorageTarget::Carried because no member has storage
 * of its own: the root repeater's target stores every leaf in the tree, at
 * the leaf's address.
 *
 * The two targets are the two strategies the storage contract names: Meta
 * keeps one row per leaf, keyed by address; Table keeps one items-table row
 * per leaf, indexed for the member-qualified query. A repeater that is
 * queried may only take the second.
 */
final readonly class RepeaterField extends Field
{
    /** The deepest legal nesting: the root plus two nested levels. */
    public const int MAX_DEPTH = 3;

    public function __construct(
        string $id,
        StorageTarget $storage,
        public Field|RepeaterItem $item,
        public ?int $expectedMaxItems = null,
        public bool $queried = false,
        public RepeaterLayout $layout = RepeaterLayout::Stacked,
        ?PersonalData $personalData = null,
        ?string $label = null,
    ) {
        parent::__construct($id, $storage, $personalData, $label);

        if (null !== $expectedMaxItems && $expectedMaxItems < 1) {
            throw InvalidFieldDefinition::itemExpectation($id, $expectedMaxItems);
        }

        if (StorageTarget::Carried === $storage && $queried) {
            throw InvalidStorageCombination::queriedCarriedRepeater($id);
        }

        $this->assertTree($id, $this->members(), 1);
    }

    /**
     * The greatest number of leaf rows one object can own in this group, or null
     * when the declaration gives no bound to derive one from.
     *
     * This is the number the page prime needs, and it is only a bound because the
     * write path refuses a group larger than `expectedMaxItems`: an enforced maximum
     * is a fact about the stored data and a hoped-for one is a LIMIT that truncates.
     * A nested repeater multiplies into its parent's total, and a nested repeater
     * that declares nothing leaves the whole group unbounded - a parent cannot be
     * primed on its child's promise, so it declines rather than guessing at how many
     * blocks a section holds.
     */
    public function maxLeavesPerObject(): ?int
    {
        if (null === $this->expectedMaxItems) {
            return null;
        }

        $perItem = 0;

        foreach ($this->members() as $member) {
            if ($member instanceof self) {
                $nested = $member->maxLeavesPerObject();

                if (null === $nested) {
                    return null;
                }

                $perItem += $nested;

                continue;
            }

            ++$perItem;
        }

        return $this->expectedMaxItems * $perItem;
    }

    /**
     * The item's fields: the one field of a scalar item, or the declared
     * list of a composite item.
     *
     * @return list<Field>
     */
    public function members(): array
    {
        return $this->item instanceof Field ? [$this->item] : $this->item->fields;
    }

    /**
     * A repeater's stored value has no scalar form: items travel as arrays
     * through setItems(), and a scalar into a repeater is a loud refusal.
     */
    #[\Override]
    public function sanitise(string|int|float|bool|null $value): string|int|float|bool|null
    {
        if (null === $value) {
            return null;
        }

        throw InvalidFieldWrite::scalarIntoRepeater($this->id);
    }

    #[\Override]
    public function cast(string|int|float|bool|null $raw): ?string
    {
        return null === $raw ? null : (string) $raw;
    }

    /**
     * The subtree rules, enforced here because the root's id names every
     * refusal: every member is Carried, member ids are unique across the
     * whole nesting (so a leaf is addressable unambiguously), and the depth
     * cap holds.
     *
     * @param list<Field> $members
     */
    private function assertTree(string $rootId, array $members, int $depth): void
    {
        if ($this->item instanceof RepeaterItem && [] === $this->item->fields) {
            throw InvalidFieldDefinition::emptyRepeaterItem($rootId);
        }

        $seen = [];

        foreach ($members as $member) {
            if (StorageTarget::Carried !== $member->storage) {
                throw InvalidFieldDefinition::memberNotCarried($rootId, $member->id);
            }

            if (isset($seen[$member->id])) {
                throw InvalidFieldDefinition::duplicateMemberId($rootId, $member->id);
            }

            $seen[$member->id] = true;

            if ($member instanceof RepeaterField) {
                if ($depth + 1 > self::MAX_DEPTH) {
                    throw InvalidFieldDefinition::nestingTooDeep($rootId, $member->id);
                }

                $this->assertTree($rootId, $member->members(), $depth + 1);
            }
        }
    }

    #[\Override]
    public function type(): FieldType
    {
        return FieldType::Repeater;
    }
}
