<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * The save boundary's authorisation guard fired: the current user may not edit
 * the object the write targets. The object-ID meta capability is the only
 * authorisation a field write accepts; a capability compared by role is a
 * defect. Nothing is written.
 */
final class AuthorizationDenied extends \RuntimeException implements MahoutException
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
            sprintf('The current user may not edit object %d; the field write was refused.', $objectId),
            $objectId,
        );
    }

    public function objectId(): int
    {
        return $this->objectId;
    }
}
