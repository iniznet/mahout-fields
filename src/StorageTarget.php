<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * Where a field's value is stored. Declared per field, required, with no
 * default: the developer answers "will this be queried?" once, in code, where
 * review can see it.
 *
 * Carried is a member's answer: the field has no independent storage, and its
 * leaf values live wherever the enclosing repeater stores them. A Carried
 * field outside a repeater item is refused at registration.
 */
enum StorageTarget: string
{
    case Meta = 'meta';
    case Table = 'table';
    case Carried = 'carried';
}
