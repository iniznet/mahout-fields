<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A group or field id violates the declaration naming convention: lowercase
 * snake_case, bounded length. The id is the value table's key half and the
 * per-field filter's suffix, so a mangled id is a different key to every
 * subsystem that compares it.
 */
final class InvalidFieldId extends \InvalidArgumentException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $kind,
        private readonly string $id,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $kind, string $id): self
    {
        return new self(sprintf('The %s id "%s" must be lowercase snake_case.', $kind, $id), $kind, $id);
    }

    public static function tooLong(string $kind, string $id, int $maximum): self
    {
        return new self(sprintf('The %s id "%s" is longer than the %d-character maximum.', $kind, $id, $maximum), $kind, $id);
    }

    public static function privacyPolicyMissing(string $fieldId): self
    {
        return new self(sprintf('User-scoped field "%s" declares no PersonalData policy; the policy is a required declaration with no default.', $fieldId), 'field', $fieldId);
    }

    public static function emptyGroup(string $groupId): self
    {
        return new self(sprintf('Field group "%s" declares no fields.', $groupId), 'group', $groupId);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function id(): string
    {
        return $this->id;
    }
}
