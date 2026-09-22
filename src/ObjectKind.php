<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * The value table's object_kind discriminator. A small integer rather than a
 * string because it leads the primary key.
 *
 * The option context has no kind: an option is a singleton read by key and
 * never a row.
 */
enum ObjectKind: int
{
    case Post = 1;
    case User = 2;
    case Term = 3;

    public static function fromContext(ObjectContext $context): ?self
    {
        return match ($context) {
            ObjectContext::Post => self::Post,
            ObjectContext::User => self::User,
            ObjectContext::Term => self::Term,
            ObjectContext::Option => null,
        };
    }

    public function context(): ObjectContext
    {
        return match ($this) {
            self::Post => ObjectContext::Post,
            self::User => ObjectContext::User,
            self::Term => ObjectContext::Term,
        };
    }
}
