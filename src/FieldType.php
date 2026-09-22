<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * The closed set of field types the storage core ships. Each names the value
 * column a Table row stores it in, through FieldValuesTable::columnFor().
 */
enum FieldType: string
{
    case Text = 'text';
    case TextArea = 'textarea';
    case Email = 'email';
    case Url = 'url';
    case Choice = 'choice';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case Repeater = 'repeater';
}
