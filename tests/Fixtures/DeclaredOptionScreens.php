<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Fixtures;

use Iniznet\Mahout\Fields\Contracts\OptionScreens;
use Iniznet\Mahout\Fields\OptionScreen;

/**
 * The array-backed OptionScreens fake: the shape a host's declaration
 * collection produces. It is deliberately the same shape as the theme's
 * panels collection -- a list, an emptiness test and iteration -- because
 * that shape is the contract's, and a fake that fit a narrower one would
 * prove nothing.
 *
 * @internal
 */
final class DeclaredOptionScreens implements OptionScreens
{
    /** @param list<OptionScreen> $screens */
    public function __construct(private readonly array $screens = [])
    {
    }

    #[\Override]
    public function isEmpty(): bool
    {
        return [] === $this->screens;
    }

    /** @return \ArrayIterator<int, OptionScreen> */
    #[\Override]
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->screens);
    }
}
