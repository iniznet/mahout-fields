<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Exception;

/**
 * A field's group context and the object a caller named do not agree: a user
 * field read with a post id, an option field addressed with a row object, or
 * a panel's props whose object kind and object id disagree -- the option
 * context with an object id, or a row kind with none. The mismatch is a
 * caller bug and is refused, never coerced.
 */
final class InvalidFieldContext extends \LogicException implements MahoutException
{
    private function __construct(
        string $message,
        private readonly string $fieldId,
        private readonly string $declared,
        private readonly string $given,
    ) {
        parent::__construct($message);
    }

    public static function mismatch(string $fieldId, string $declared, string $given): self
    {
        return new self(sprintf('Field "%s" is declared for the %s context; the call named %s.', $fieldId, $declared, $given), $fieldId, $declared, $given);
    }

    public static function migrationUnsupported(string $fieldId, string $declared): self
    {
        return new self(
            sprintf('Field "%s" is declared for the %s context; only post-context fields migrate, because revisions are post-only.', $fieldId, $declared),
            $fieldId,
            $declared,
            'post',
        );
    }

    public static function panelObject(string $groupId, string $declared, int $objectId): self
    {
        $given = (string) $objectId;

        return new self(
            sprintf('Panel "%s" is declared for the %s context; its props name the object id %s.', $groupId, $declared, $given),
            $groupId,
            $declared,
            $given,
        );
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function declared(): string
    {
        return $this->declared;
    }

    public function given(): string
    {
        return $this->given;
    }
}
