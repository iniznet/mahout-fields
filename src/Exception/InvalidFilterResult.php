<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A filter this package applies returned something outside its contract. A
 * filter result is a trust boundary: the wrong shape is refused, never
 * coerced.
 */
final class InvalidFilterResult extends \UnexpectedValueException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $hook,
    ) {
        parent::__construct($message);
    }

    public static function notAStorageTarget(string $hook): self
    {
        return new self('The storage_target filter must return a StorageTarget.', $hook);
    }

    public static function notASanitisedScalar(string $hook): self
    {
        return new self('The sanitized_value filter must return a scalar or null.', $hook);
    }

    public function hook(): string
    {
        return $this->hook;
    }
}
