<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\Table;

/**
 * The repeater items table: one row per item at an explicit position. Repeated
 * meta rows order by meta_id, which is insertion order and not a documented
 * contract; the position column is.
 *
 * The generic table holds one scalar per item (text or integer). A repeater
 * whose items carry more than one value has outgrown the generic shape and
 * binds a dedicated table, which is the documented escalation. The column
 * names are not a public surface; the accessors below carry them to the two
 * call sites, and Row validates every key against the declaration.
 */
final readonly class FieldItemsTable
{
    private const string OBJECT_KIND = 'object_kind';

    private const string OBJECT_ID = 'object_id';

    private const string FIELD_ID = 'field_id';

    private const string POSITION = 'position';

    private const string VALUE_TEXT = 'value_text';

    private const string VALUE_INT = 'value_int';

    private const string SUFFIX = 'mahout_field_items';

    public static function table(string $prefix, string $charsetCollate): Table
    {
        return new Table(
            name: Identifier::prefixed($prefix, self::SUFFIX),
            columns: [
                Column::tinyIntUnsigned(self::OBJECT_KIND),
                Column::reference(self::OBJECT_ID),
                Column::varchar(self::FIELD_ID, 191),
                Column::intUnsigned(self::POSITION),
                Column::text(self::VALUE_TEXT)->nullable(),
                Column::bigInt(self::VALUE_INT)->nullable(),
            ],
            indexes: [
                Index::primary(
                    IndexColumn::of(self::OBJECT_KIND),
                    IndexColumn::of(self::OBJECT_ID),
                    IndexColumn::of(self::FIELD_ID),
                    IndexColumn::of(self::POSITION),
                ),
            ],
            engine: \Iniznet\Mahout\Db\Engine::InnoDB,
            charsetCollate: $charsetCollate,
        );
    }

    public static function objectKindColumn(): string
    {
        return self::OBJECT_KIND;
    }

    public static function objectIdColumn(): string
    {
        return self::OBJECT_ID;
    }

    public static function fieldIdColumn(): string
    {
        return self::FIELD_ID;
    }

    public static function positionColumn(): string
    {
        return self::POSITION;
    }

    public static function textColumn(): string
    {
        return self::VALUE_TEXT;
    }

    public static function intColumn(): string
    {
        return self::VALUE_INT;
    }

    /**
     * Whether the generic items table can hold this repeater's item type in
     * one of its two value columns. A type with no column needs a dedicated
     * table, which is the storage contract's escalation.
     */
    public static function holdsItem(FieldType $type): bool
    {
        return \in_array($type, [FieldType::Text, FieldType::TextArea, FieldType::Email, FieldType::Url, FieldType::Choice, FieldType::Integer, FieldType::Boolean], true);
    }
}
