<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\Exception\MalformedNoticePayload;

/**
 * One queued refusal, taken off the transient. The reason string is the
 * closed SaveRefusalReason set's value; the reference is the diagnostics
 * record the refusal was written under, and the notice surfaces both.
 */
final readonly class QueuedRefusal
{
    private function __construct(
        public string $groupId,
        public string $reason,
        public string $reference,
    ) {
    }

    /**
     * @param array<mixed, mixed> $payload
     *
     * @throws MalformedNoticePayload when the payload is not the shape the store wrote
     */
    public static function fromPayload(array $payload): self
    {
        $group = $payload['group'] ?? null;
        $reason = $payload['reason'] ?? null;
        $reference = $payload['reference'] ?? null;

        if (!\is_string($group) || !\is_string($reason) || !\is_string($reference)) {
            throw MalformedNoticePayload::forQueue();
        }

        return new self($group, $reason, $reference);
    }
}
