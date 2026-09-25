<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * The queued notice transient does not carry the shape the store wrote. The
 * notice is refused, never surfaced with substituted fields.
 */
final class MalformedNoticePayload extends \UnexpectedValueException implements MahoutException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forQueue(): self
    {
        return new self('The queued notice payload is missing its group, reason or reference; the store refuses to surface a shape it did not write.');
    }
}
