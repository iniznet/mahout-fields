<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Fixtures;

use Iniznet\Mahout\Kernel\Contracts\QuerySource;

/** A QuerySource that counts what the test told it and nothing else. */
final class CountingQuerySource implements QuerySource
{
    private int $count = 0;

    #[\Override]
    public function count(): int
    {
        return $this->count;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function statements(): array
    {
        return [];
    }

    public function advance(int $by = 1): void
    {
        $this->count += $by;
    }
}
