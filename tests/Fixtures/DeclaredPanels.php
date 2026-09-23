<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Fixtures;

use Iniznet\Mahout\Fields\Contracts\Panels;
use Iniznet\Mahout\Fields\FieldPanel;

/**
 * The array-backed Panels fake: the shape a host's declaration collection
 * produces. The host's own collection is a list of panels it built from its
 * config, and this is that list with no config loader behind it.
 *
 * It is deliberately the same shape as the theme's collection -- a list, an
 * emptiness test, a per-post-type slice and iteration -- because that shape is
 * the contract's, and a fake that fit a narrower one would prove nothing.
 *
 * @internal
 */
final class DeclaredPanels implements Panels
{
    /** @param list<FieldPanel> $panels */
    public function __construct(private readonly array $panels = [])
    {
    }

    #[\Override]
    public function isEmpty(): bool
    {
        return [] === $this->panels;
    }

    #[\Override]
    public function forPostType(string $postType): array
    {
        return array_values(array_filter(
            $this->panels,
            static fn (FieldPanel $panel): bool => $panel->postType === $postType,
        ));
    }

    /** @return \ArrayIterator<int, FieldPanel> */
    #[\Override]
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->panels);
    }
}
