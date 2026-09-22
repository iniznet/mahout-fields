<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * The revision mirror is not the payload the field layer requires, or the meta
 * backend refused to hold it. The mirror is the reference the lost-update guard
 * and the revision restore both read; a broken one is refused loudly, never
 * substituted with an empty row set.
 */
final class InvalidMirrorPayload extends \UnexpectedValueException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $groupId,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $groupId, string $jsonError): self
    {
        return new self(
            sprintf('The revision mirror of group "%s" is not valid JSON: %s', $groupId, $jsonError),
            $groupId,
            'malformed',
        );
    }

    public static function notAnEnvelope(string $groupId): self
    {
        return new self(
            sprintf('The revision mirror of group "%s" is not a {"v":1,"schema":1,"hash":...,"rows":[...]} payload.', $groupId),
            $groupId,
            'shape',
        );
    }

    public static function unsupportedVersion(string $groupId, int $version): self
    {
        return new self(
            sprintf('The revision mirror of group "%s" declares version %d; this package reads version 1.', $groupId, $version),
            $groupId,
            'version',
        );
    }

    public static function malformedRow(string $groupId, int $position): self
    {
        return new self(
            sprintf('The revision mirror of group "%s" carries a malformed row at position %d.', $groupId, $position),
            $groupId,
            'row',
        );
    }

    public static function unknownField(string $groupId, string $fieldId): self
    {
        return new self(
            sprintf('The revision mirror of group "%s" carries field "%s", which the group no longer declares; the mirror is newer than the code.', $groupId, $fieldId),
            $groupId,
            'unknown_field',
        );
    }

    public static function writeRefused(string $groupId): self
    {
        return new self(
            sprintf('The meta backend refused the revision mirror write for group "%s".', $groupId),
            $groupId,
            'backend',
        );
    }

    public static function unreadable(string $groupId): self
    {
        return new self(
            sprintf('The revision mirror of group "%s" is not this package\'s shape; refusing to coerce it.', $groupId),
            $groupId,
            'shape',
        );
    }

    public function groupId(): string
    {
        return $this->groupId;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
