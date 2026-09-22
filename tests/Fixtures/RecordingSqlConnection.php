<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Fixtures;

use Iniznet\Mahout\Db\Contracts\SqlConnection;

/**
 * A recording decorator over the real connection: the query-shape tests read
 * the exact statement the builder composed, and nothing else.
 */
final class RecordingSqlConnection implements SqlConnection
{
    /** @var list<string> */
    public array $statements = [];

    public function __construct(private readonly SqlConnection $inner)
    {
    }

    #[\Override]
    public function execute(string $statement): void
    {
        $this->statements[] = $statement;
        $this->inner->execute($statement);
    }

    #[\Override]
    public function executePrepared(string $statement, string|int ...$values): void
    {
        $this->statements[] = $statement;
        $this->inner->executePrepared($statement, ...$values);
    }

    #[\Override]
    public function rows(string $statement): array
    {
        $this->statements[] = $statement;

        return $this->inner->rows($statement);
    }

    #[\Override]
    public function rowsPrepared(string $statement, string|int ...$values): array
    {
        $this->statements[] = $statement;

        return $this->inner->rowsPrepared($statement, ...$values);
    }

    #[\Override]
    public function prefix(): string
    {
        return $this->inner->prefix();
    }

    #[\Override]
    public function charsetCollate(): string
    {
        return $this->inner->charsetCollate();
    }
}
