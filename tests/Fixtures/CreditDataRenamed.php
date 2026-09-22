<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Fixtures;

/**
 * The same domain value as CreditData with both properties renamed. The
 * storage mapping is unchanged, because the codec maps to the STORAGE SHAPE,
 * never to the property names: renaming the DTO must not change one stored
 * byte.
 *
 * @internal
 */
final readonly class CreditDataRenamed
{
    public function __construct(
        public string $creditedAs,
        public string $fullName,
    ) {
    }

    /** @return array<string, string|int|float|bool> */
    public function toStorage(): array
    {
        return [
            'role' => $this->creditedAs,
            'name' => $this->fullName,
        ];
    }
}
