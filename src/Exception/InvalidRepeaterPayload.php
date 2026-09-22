<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A stored repeater payload is not the versioned envelope the field layer
 * requires. Reading it would silently substitute an empty list for data that
 * exists in another shape, so it refuses instead.
 */
final class InvalidRepeaterPayload extends \UnexpectedValueException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $jsonError): self
    {
        return new self('The repeater payload is not valid JSON: '.$jsonError, 'malformed');
    }

    public static function notAnEnvelope(): self
    {
        return new self('The repeater payload is not a {"v":1,"items":[...]} envelope.', 'shape');
    }

    public static function unsupportedVersion(int $version): self
    {
        return new self(sprintf('The repeater payload declares version %d; this package reads version 1.', $version), 'version');
    }

    public static function malformedItem(int $position): self
    {
        return new self(sprintf('The repeater payload item at position %d is neither a scalar nor a record of scalars.', $position), 'item');
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
