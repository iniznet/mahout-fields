<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Fixtures;

use Iniznet\Mahout\Fields\Contracts\RequestInput;

/**
 * The array-backed RequestInput fake: the shape a host's request adapter
 * produces, without a superglobal in sight.
 */
final class ArrayRequestInput implements RequestInput
{
    /**
     * @param array<string, mixed>                                                    $body    the submitted form, unslashed
     * @param array<string, array<string, string|list<string>|null>>                  $groups
     * @param array<string, string>                                                   $hashes
     * @param array<string, string>                                                   $params  the request parameters, query and body merged
     */
    public function __construct(
        private array $body = [],
        private array $groups = [],
        private array $hashes = [],
        private array $params = [],
    ) {
    }

    #[\Override]
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->body);
    }

    #[\Override]
    public function string(string $key): ?string
    {
        $value = $this->body[$key] ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }

    #[\Override]
    public function groups(): array
    {
        return $this->groups;
    }

    #[\Override]
    public function hashes(): array
    {
        return $this->hashes;
    }

    #[\Override]
    public function param(string $key): ?string
    {
        $value = $this->params[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
