<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * No registered group carries this id. Addressing a group that was never
 * declared is a programmer error, not an expected absence.
 */
final class GroupNotFound extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $groupId,
    ) {
        parent::__construct($message);
    }

    public static function forId(string $groupId): self
    {
        return new self(sprintf('Group "%s" is not registered.', $groupId), $groupId);
    }

    public function groupId(): string
    {
        return $this->groupId;
    }
}
