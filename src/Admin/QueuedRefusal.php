<?php

/**
 * One queued refusal, taken off the transient. The reason string is the
 * closed SaveRefusalReason set's value; the reference is the diagnostics
 * record the refusal was written under, and the notice surfaces both.
 */

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

final readonly class QueuedRefusal
{
    private function __construct(
        public string $groupId,
        public string $reason,
        public string $reference,
    ) {
    }

    /** @param array<mixed, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            \is_string($payload['group'] ?? null) ? $payload['group'] : '',
            \is_string($payload['reason'] ?? null) ? $payload['reason'] : '',
            \is_string($payload['reference'] ?? null) ? $payload['reference'] : '',
        );
    }
}
