<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\Table;

/**
 * The generic typed value table, declared exactly as the storage contract
 * fixes it: one row per field per object, every value column indexed, and
 * field_id leading each secondary index so a query is a range scan on one
 * field rather than a table scan.
 *
 * A value factory: it resolves no collaborator, so the static call is a
 * permitted one. The prefix is applied at declaration, never at query time.
 * The column names are not a public surface: a row's keys are validated
 * against the declaration at Row construction, and the adapters read them
 * through the accessors below.
 */
final readonly class FieldValuesTable
{
    private const string OBJECT_KIND = 'object_kind';

    private const string OBJECT_ID = 'object_id';

    private const string FIELD_ID = 'field_id';

    private const string VALUE_TEXT = 'value_text';

    private const string VALUE_INT = 'value_int';

    private const string VALUE_DEC = 'value_dec';

    private const string VALUE_DATE = 'value_date';

    private const string SUFFIX = 'mahout_field_values';

    public static function table(string $prefix, string $charsetCollate): Table
    {
        return new Table(
            name: \Iniznet\Mahout\Db\Identifier::prefixed($prefix, self::SUFFIX),
            columns: [
                Column::tinyIntUnsigned(self::OBJECT_KIND),
                Column::reference(self::OBJECT_ID),
                Column::varchar(self::FIELD_ID, 191),
                Column::text(self::VALUE_TEXT)->nullable(),
                Column::bigInt(self::VALUE_INT)->nullable(),
                Column::decimal(self::VALUE_DEC, 20, 6)->nullable(),
                Column::dateTime(self::VALUE_DATE)->nullable(),
            ],
            indexes: [
                Index::primary(
                    IndexColumn::of(self::OBJECT_KIND),
                    IndexColumn::of(self::OBJECT_ID),
                    IndexColumn::of(self::FIELD_ID),
                ),
                Index::key('field_text', IndexColumn::of(self::FIELD_ID), IndexColumn::prefixed(self::VALUE_TEXT, 191)),
                Index::key('field_int', IndexColumn::of(self::FIELD_ID), IndexColumn::of(self::VALUE_INT)),
                Index::key('field_dec', IndexColumn::of(self::FIELD_ID), IndexColumn::of(self::VALUE_DEC)),
                Index::key('field_date', IndexColumn::of(self::FIELD_ID), IndexColumn::of(self::VALUE_DATE)),
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

    public static function textColumn(): string
    {
        return self::VALUE_TEXT;
    }

    public static function intColumn(): string
    {
        return self::VALUE_INT;
    }

    public static function decimalColumn(): string
    {
        return self::VALUE_DEC;
    }

    public static function dateColumn(): string
    {
        return self::VALUE_DATE;
    }

    /**
     * The value column a field type is stored in, or null for the repeater,
     * which is never a value row.
     */
    public static function columnFor(FieldType $type): ?string
    {
        return match ($type) {
            FieldType::Text, FieldType::TextArea, FieldType::Email, FieldType::Url, FieldType::Choice => self::VALUE_TEXT,
            FieldType::Integer, FieldType::Boolean => self::VALUE_INT,
            FieldType::Decimal => self::VALUE_DEC,
            FieldType::Date => self::VALUE_DATE,
            FieldType::Repeater => null,
        };
    }

    /**
     * The value column a repeater's item type lands in, or the text column for
     * an item type the generic table cannot hold -- which the registry refuses
     * before a row is ever written.
     */
    public static function itemColumnFor(FieldType $type): string
    {
        return self::columnFor($type) ?? self::VALUE_TEXT;
    }
}
