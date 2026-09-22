<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * The kind of object a field group's values belong to. Declared on the group;
 * it decides which backend each target writes.
 */
enum ObjectContext: string
{
    case Post = 'post';
    case User = 'user';
    case Term = 'term';
    case Option = 'option';
}
