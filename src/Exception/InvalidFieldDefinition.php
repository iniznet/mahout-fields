<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

use Iniznet\Mahout\Fields\RepeaterField;

/**
 * A field declaration breaks its own type's rules: a choice field with no
 * options, a repeater member with storage of its own, nesting past the cap.
 * The declaration is refused where it is written.
 */
final class InvalidFieldDefinition extends \LogicException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $fieldId,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function emptyChoiceSet(string $fieldId): self
    {
        return new self(sprintf('Choice field "%s" declares no options.', $fieldId), $fieldId, 'choices');
    }

    public static function emptyRepeaterItem(string $fieldId): self
    {
        return new self(sprintf('Repeater "%s" declares a composite item with no members.', $fieldId), $fieldId, 'item');
    }

    public static function memberNotCarried(string $fieldId, string $memberId): self
    {
        return new self(sprintf('Repeater "%s" member "%s" declares storage of its own; a member is Carried, and the root repeater stores every leaf.', $fieldId, $memberId), $fieldId, 'member');
    }

    public static function duplicateMemberId(string $fieldId, string $memberId): self
    {
        return new self(sprintf('Repeater "%s" declares member "%s" twice; member ids are unique across the whole nesting.', $fieldId, $memberId), $fieldId, 'member');
    }

    public static function nestingTooDeep(string $fieldId, string $memberId): self
    {
        return new self(sprintf('Repeater "%s" nests "%s" past the depth cap of %d levels.', $fieldId, $memberId, RepeaterField::MAX_DEPTH), $fieldId, 'depth');
    }

    public static function itemExpectation(string $fieldId, int $declared): self
    {
        return new self(sprintf('Repeater "%s" declares expectedMaxItems of %d; the count must be positive.', $fieldId, $declared), $fieldId, 'items');
    }

    public static function noControl(string $type): self
    {
        return new self(sprintf('Field type "%s" has no editor control; register one through the editor_controls filter.', $type), $type, 'control');
    }

    public static function noValueColumn(string $fieldId): self
    {
        return new self(sprintf('Field "%s" has no value column; a repeater is queried through its items, not the value table.', $fieldId), $fieldId, 'value-column');
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
