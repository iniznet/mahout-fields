<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A group id was registered twice. Registry state is written once per group;
 * a second registration would fire group_registered for a group the
 * composition root already declared.
 */
final class GroupAlreadyRegistered extends \LogicException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $groupId,
    ) {
        parent::__construct($message);
    }

    public static function forId(string $groupId): self
    {
        return new self(sprintf('Field group "%s" is already registered.', $groupId), $groupId);
    }

    public function groupId(): string
    {
        return $this->groupId;
    }
}
