<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A write asked an adapter for a shape its backend cannot hold: a JSON payload
 * into the items table, items into a scalar field, a row for the option
 * context. The storage adapters have exactly one shape each.
 */
final class InvalidFieldWrite extends \LogicException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $fieldId,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function scalarIntoRepeater(string $fieldId): self
    {
        return new self(sprintf('Field "%s" is a repeater; write its items through setItems(), never a scalar.', $fieldId), $fieldId, 'shape');
    }

    public static function badRepeaterAddress(string $fieldId): self
    {
        return new self(sprintf('Field "%s" received an item shape that breaks the address grammar; declare the members the form submits.', $fieldId), $fieldId, 'shape');
    }

    public static function addressTooLong(string $fieldId): self
    {
        return new self(sprintf('Field "%s" produced a leaf address past the 191-byte cap; shorten a member id or reduce the nesting.', $fieldId), $fieldId, 'shape');
    }

    public static function itemsIntoScalar(string $fieldId): self
    {
        return new self(sprintf('Field "%s" is scalar; write it through set(), never as an item list.', $fieldId), $fieldId, 'shape');
    }

    public static function jsonIntoItemsTable(string $fieldId): self
    {
        return new self(sprintf('Repeater "%s" binds the items table; a JSON payload is the Meta shape, not this one.', $fieldId), $fieldId, 'target');
    }

    public static function optionRow(string $fieldId): self
    {
        return new self(sprintf('Field "%s" is option-context; the value table has no option row.', $fieldId), $fieldId, 'context');
    }

    public static function routeWritesMeta(string $fieldId): self
    {
        return new self(sprintf('Field "%s" is not Table-bound; the field route writes Table storage only. A Meta field is written through register_post_meta().', $fieldId), $fieldId, 'target');
    }

    public static function unreadableMeta(string $fieldId): self
    {
        return new self(sprintf('The stored value under field "%s" is not this package\'s shape; refusing to coerce it.', $fieldId), $fieldId, 'shape');
    }

    public static function metaRefused(string $fieldId): self
    {
        return new self(sprintf('The meta backend refused the write for field "%s".', $fieldId), $fieldId, 'backend');
    }

    public static function foreignObjectKind(string $fieldId): self
    {
        return new self(
            sprintf('Field "%s" holds a row for an object kind its group does not declare; refusing to move it.', $fieldId),
            $fieldId,
            'kind',
        );
    }

    public static function recordedItems(string $fieldId): self
    {
        return new self(
            sprintf('Repeater "%s" stores records; the items table holds one scalar per item. Bind a dedicated table instead of migrating.', $fieldId),
            $fieldId,
            'shape',
        );
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
