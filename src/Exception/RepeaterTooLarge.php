<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A repeater payload exceeded a size the declaration promised to respect.
 *
 * The byte cap is checked at encode time, where the payload's size is known.
 * The item cap is the declaration's own expectedMaxItems; a declared
 * expectation nothing enforced would be a gate that exists only in prose.
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

    public static function payload(int $bytes, int $cap): self
    {
        return new self(
            sprintf('The encoded repeater payload is %d bytes; the cap is %d.', $bytes, $cap),
            $bytes,
            $cap,
        );
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
