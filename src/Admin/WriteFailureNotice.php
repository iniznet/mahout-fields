<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

/**
 * The write-failure notice: the refusal store. save_post must not wp_die(), so
 * a refused classic-path save is queued as a one-shot transient for the user
 * who submitted the form and surfaced on the next admin screen load by
 * Admin\WriteFailureNoticeRenderer, which Admin\FieldsUiProvider attaches.
 * An option screen's refused save queues under the screen's own key and is
 * surfaced the same way, on the screen's next load. Queue and take are the
 * only operations here, and nothing is retried and nothing is substituted.
 */
final readonly class WriteFailureNotice
{
    private const int TTL = \HOUR_IN_SECONDS;

    /** Queue one refusal for the post's editing user; never throws. */
    public function queue(int $postId, SaveRefusal $refusal): void
    {
        \set_transient($this->key($postId), self::payload($refusal), self::TTL);
    }

    /** Take the queued refusal for this user and post, or return null. */
    public function take(int $userId, int $postId): ?QueuedRefusal
    {
        return $this->takeKey($this->key($postId));
    }

    /** Queue one refusal for one option screen; never throws. */
    public function queueForScreen(string $slug, SaveRefusal $refusal): void
    {
        \set_transient($this->screenKey($slug), self::payload($refusal), self::TTL);
    }

    /** Take the queued refusal for one option screen, or return null. */
    public function takeForScreen(string $slug): ?QueuedRefusal
    {
        return $this->takeKey($this->screenKey($slug));
    }

    /** @return array{group: string, reason: string, reference: string} */
    private static function payload(SaveRefusal $refusal): array
    {
        return ['group' => $refusal->groupId, 'reason' => $refusal->reason->value, 'reference' => $refusal->reference];
    }

    private function takeKey(string $key): ?QueuedRefusal
    {
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

    private function screenKey(string $slug): string
    {
        return 'mahout_fields_write_failed_screen_'.$slug;
    }
}
