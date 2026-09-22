<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

/**
 * The per-user write-failure notice. save_post must not wp_die(), so a
 * refused classic-path save is queued as a one-shot transient for the user
 * who submitted the form and surfaced by the host's AdminProvider on the
 * next admin screen load. Queue and take are the only operations; nothing is
 * retried and nothing is substituted.
 */
final readonly class WriteFailureNotice
{
    private const int TTL = \HOUR_IN_SECONDS;

    /** Queue one refusal for the post's editing user; never throws. */
    public function queue(int $postId, SaveRefusal $refusal): void
    {
        \set_transient(
            $this->key($postId),
            ['group' => $refusal->groupId, 'reason' => $refusal->reason->value, 'reference' => $refusal->reference],
            self::TTL,
        );
    }

    /** Take the queued refusal for this user and post, or return null. */
    public function take(int $userId, int $postId): ?QueuedRefusal
    {
        $key = $this->key($postId);
        $payload = \get_transient($key);

        if (!\is_array($payload)) {
            return null;
        }

        \delete_transient($key);

        return QueuedRefusal::fromPayload($payload);
    }

    private function key(int $postId): string
    {
        return 'mahout_fields_write_failed_'.(int) $postId;
    }
}

/** One queued refusal, taken off the transient. */
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
