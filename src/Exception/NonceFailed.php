<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * The panel's nonce did not verify: the form is stale, forged, or replayed.
 * The refusal records one warning, writes nothing, and never calls wp_die()
 * -- inside save_post, core has already written the post and a termination
 * would abort the response. Nothing is written.
 */
final class NonceFailed extends \RuntimeException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly int $objectId,
    ) {
        parent::__construct($message);
    }

    public static function forObject(int $objectId): self
    {
        return new self(
            sprintf('The field panel nonce for object %d did not verify; the write was refused.', $objectId),
            $objectId,
        );
    }

    public function objectId(): int
    {
        return $this->objectId;
    }
}
