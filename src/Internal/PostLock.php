<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

/**
 * The post lock, read once behind a function_exists() guard.
 *
 * wp_check_post_lock() lives in wp-admin/includes/post.php, an admin file the
 * REST context does not load. An absent function means no lock exists -- 16
 * §4.2 guard 4's documented reading -- and never degrades into a refusal.
 * The lock is set by core on both editor paths; this package only reads it.
 *
 * @internal
 */
final readonly class PostLock
{
    /**
     * @return int|null the lock holder's user id, or null when no lock exists
     */
    public function holder(int $postId): ?int
    {
        if (!\function_exists('wp_check_post_lock')) {
            return null;
        }

        $holder = \wp_check_post_lock($postId);

        return \is_int($holder) ? $holder : null;
    }
}
