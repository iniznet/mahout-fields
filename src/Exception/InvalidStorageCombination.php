<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A field's declaration pairs an object context with a storage target that
 * cannot serve it.
 *
 * Two combinations are unrepresentable by design: an option-context field on
 * the generic value table (options are singletons read by key; a table buys
 * nothing), and a repeater declared queried on the Meta target (a queried
 * repeater must bind the items table, not JSON).
 */
final class InvalidStorageCombination extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $fieldId,
        private readonly string $context,
        private readonly string $storage,
    ) {
        parent::__construct($message);
    }

    public static function optionContextTable(string $fieldId): self
    {
        return new self(
            sprintf('Field "%s" declares the Option context with Table storage; an option-context field is Meta always.', $fieldId),
            $fieldId,
            'option',
            'table',
        );
    }

    public static function queriedRepeaterInMeta(string $fieldId): self
    {
        return new self(
            sprintf('Repeater "%s" is declared queried with Meta storage; a queried repeater must bind the items table.', $fieldId),
            $fieldId,
            'any',
            'meta',
        );
    }

    public static function repeaterItemWithoutColumn(string $fieldId, string $itemType): self
    {
        return new self(
            sprintf('Repeater "%s" declares %s items on Table storage; the generic items table holds text and integer items only, so bind a dedicated table or store this repeater as JSON.', $fieldId, $itemType),
            $fieldId,
            'any',
            'table',
        );
    }

    /**
     * A Meta-bound field has no row to query: its value is load-with-the-entity
     * and the value table never holds it. Filtered reads are Table's own job.
     */
    public static function queryAgainstMeta(string $fieldId): self
    {
        return new self(
            sprintf('Field "%s" is bound to Meta storage; only a Table-bound field can be queried through the value table.', $fieldId),
            $fieldId,
            'any',
            'meta',
        );
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function context(): string
    {
        return $this->context;
    }

    public function storage(): string
    {
        return $this->storage;
    }
}
