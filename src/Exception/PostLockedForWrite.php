<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * Another user holds the post's edit lock, so a field write would trample an
 * editor who is working right now. The lock is a concurrency control, not an
 * authorisation one, which is why it runs before the capability and the nonce
 * and refuses with its own condition. Nothing is written.
 */
final class PostLockedForWrite extends \RuntimeException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly int $objectId,
        private readonly int $holderId,
    ) {
        parent::__construct($message);
    }

    public static function forObject(int $objectId, int $holderId): self
    {
        return new self(
            sprintf('Object %d is locked by user %d; the field write was refused.', $objectId, $holderId),
            $objectId,
            $holderId,
        );
    }

    public function objectId(): int
    {
        return $this->objectId;
    }

    public function holderId(): int
    {
        return $this->holderId;
    }
}
