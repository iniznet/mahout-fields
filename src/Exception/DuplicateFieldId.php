<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * Two registered fields would answer to one id. The id is the reader's key and
 * the value table's key half, so a duplicate would silently alias two values.
 */
final class DuplicateFieldId extends \LogicException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $fieldId,
        private readonly string $groupId,
    ) {
        parent::__construct($message);
    }

    public static function inGroup(string $fieldId, string $groupId): self
    {
        return new self(sprintf('Field "%s" is declared twice in group "%s".', $fieldId, $groupId), $fieldId, $groupId);
    }

    public static function acrossGroups(string $fieldId, string $groupId): self
    {
        return new self(sprintf('Field "%s" is already registered; group "%s" cannot redeclare it.', $fieldId, $groupId), $fieldId, $groupId);
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function groupId(): string
    {
        return $this->groupId;
    }
}
