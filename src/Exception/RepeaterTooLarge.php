<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A repeater write exceeded the item count its declaration promised.
 *
 * expectedMaxItems is the declaration's own cap; an expectation nothing
 * enforced would be a gate that exists only in prose.
 */
final class RepeaterTooLarge extends \RuntimeException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly int $size,
        private readonly int $cap,
    ) {
        parent::__construct($message);
    }

    public static function items(int $count, int $declared): self
    {
        return new self(
            sprintf('The repeater write carries %d items; the declaration promises at most %d.', $count, $declared),
            $count,
            $declared,
        );
    }

    public function size(): int
    {
        return $this->size;
    }

    public function cap(): int
    {
        return $this->cap;
    }
}
