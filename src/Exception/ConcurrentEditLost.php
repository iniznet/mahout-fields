<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * The lost-update guard fired: the reference hash an editor form carried does
 * not match the row set the mirror records. Another writer changed the group
 * between render and submit. Nothing is written and nothing is merged -- two
 * concurrent edits produce one winner and one refusal, never a union.
 */
final class ConcurrentEditLost extends \RuntimeException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $groupId,
        private readonly int $objectId,
    ) {
        parent::__construct($message);
    }

    public static function forGroup(string $groupId, int $objectId): self
    {
        return new self(
            sprintf('Group "%s" on object %d changed since the form was rendered; the write was refused.', $groupId, $objectId),
            $groupId,
            $objectId,
        );
    }

    public function groupId(): string
    {
        return $this->groupId;
    }

    public function objectId(): int
    {
        return $this->objectId;
    }
}
