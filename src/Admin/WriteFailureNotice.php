<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

/**
 * The write-failure notice: the refusal store. save_post must not wp_die(), so
 * a refused classic-path save is queued as a one-shot transient keyed to the
 * user who submitted the form and the post it edits -- another user opening
 * the same post finds no notice to take -- and surfaced on the next admin
 * screen load by Admin\WriteFailureNoticeRenderer, which
 * Admin\FieldsUiProvider attaches. An option screen's refused save queues
 * under the screen's own key and is surfaced the same way, on the screen's
 * next load. Queue and take are the only operations here, and nothing is
 * retried and nothing is substituted.
 */
final readonly class WriteFailureNotice
{
    private const int TTL = \HOUR_IN_SECONDS;

    /** Queue one refusal for the user who submitted the form and the post it edits; never throws. */
    public function queue(int $userId, int $postId, SaveRefusal $refusal): void
    {
        \set_transient($this->key($userId, $postId), self::payload($refusal), self::TTL);
    }

    /** Take the queued refusal for this user and post, or return null. */
    public function take(int $userId, int $postId): ?QueuedRefusal
    {
        return $this->takeKey($this->key($userId, $postId));
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

    private function key(int $userId, int $postId): string
    {
        return 'mahout_fields_write_failed_'.(int) $userId.'__'.(int) $postId;
    }

    private function screenKey(string $slug): string
    {
        return 'mahout_fields_write_failed_screen_'.$slug;
    }
}
