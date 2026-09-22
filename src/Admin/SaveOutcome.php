<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

/**
 * One save attempt's outcome: either the group stored, with the mirror hash it
 * now carries, or it was refused, with the refusal that carries the record.
 * A refused save wrote nothing.
 */
final readonly class SaveOutcome
{
    private function __construct(
        public ?SaveRefusal $refusal,
        public string $hash,
    ) {
    }

    public static function stored(string $hash): self
    {
        return new self(null, $hash);
    }

    public static function refused(SaveRefusal $refusal): self
    {
        return new self($refusal, '');
    }

    public function isStored(): bool
    {
        return null === $this->refusal;
    }
}
