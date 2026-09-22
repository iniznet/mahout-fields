<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * Where a field's value is stored. Declared per field, required, with no
 * default: the developer answers "will this be queried?" once, in code, where
 * review can see it.
 */
enum StorageTarget: string
{
    case Meta = 'meta';
    case Table = 'table';
}
