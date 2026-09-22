<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Fixtures;

/**
 * A consumer DTO: the domain shape a codec maps FROM. The storage keys are the
 * codec's decision, not the property names.
 *
 * @internal
 */
final readonly class CreditData
{
    public function __construct(
        public string $role,
        public string $name,
    ) {
    }

    /** @return array<string, string|int|float|bool> */
    public function toStorage(): array
    {
        return [
            'role' => $this->role,
            'name' => $this->name,
        ];
    }
}
