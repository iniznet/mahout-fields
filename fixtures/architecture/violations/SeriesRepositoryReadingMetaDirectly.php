<?php

declare(strict_types=1);

namespace Iniznet\Consumer\Features\Series;

/**
 * The deliberate violation: a consumer-side repository reading a registered
 * field through a raw meta call. This is the encapsulation rule's negative
 * fixture; the shared architecture rule must fire on exactly it.
 */
final class SeriesRepository
{
    public function isbn(int $postId): string
    {
        return (string) get_post_meta($postId, 'isbn', true);
    }
}
