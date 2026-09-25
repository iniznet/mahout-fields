<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

use Iniznet\Mahout\Db\Column;
use Iniznet\Mahout\Db\Engine;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Index;
use Iniznet\Mahout\Db\IndexColumn;
use Iniznet\Mahout\Db\Table;

/**
 * The repeater leaves table: one row per leaf scalar, at its address. No
 * envelope, no nesting in storage — a nested repeater's leaves are rows of
 * the same table, their ancestry carried by the address.
 *
 * The primary key answers per-object reads: one prefix scan on (kind, id,
 * group) returns every leaf of one repeater, ordered by address. The
 * query_path index answers cross-object member queries — the developer's
 * declared choice to query repeater data, at the cost the contract states.
 *
 * The column names are not a public surface; the accessors below carry them
 * to the call sites, and Row validates every key against the declaration.
 */
final readonly class FieldLeavesTable
{
    private const string OBJECT_KIND = 'object_kind';

    private const string OBJECT_ID = 'object_id';

    private const string GROUP_ID = 'group_id';

    private const string ADDRESS = 'address';

    private const string MEMBER = 'member';

    private const string VALUE_TEXT = 'value_text';

    private const string VALUE_INT = 'value_int';

    private const string VALUE_DEC = 'value_dec';

    private const string VALUE_DATE = 'value_date';

    private const string SUFFIX = 'mahout_field_leaves';

    public static function table(string $prefix, string $charsetCollate): Table
    {
        return new Table(
            name: Identifier::prefixed($prefix, self::SUFFIX),
            columns: [
                Column::tinyIntUnsigned(self::OBJECT_KIND),
                Column::reference(self::OBJECT_ID),
                Column::varchar(self::GROUP_ID, 191),
                Column::varchar(self::ADDRESS, 191),
                Column::varchar(self::MEMBER, 64),
                Column::text(self::VALUE_TEXT)->nullable(),
                Column::bigInt(self::VALUE_INT)->nullable(),
                Column::decimal(self::VALUE_DEC, 20, 6)->nullable(),
                Column::dateTime(self::VALUE_DATE)->nullable(),
            ],
            indexes: [
                Index::primary(
                    IndexColumn::of(self::OBJECT_KIND),
                    IndexColumn::of(self::OBJECT_ID),
                    IndexColumn::of(self::GROUP_ID),
                    IndexColumn::of(self::ADDRESS),
                ),
                Index::key(
                    'query_path',
                    IndexColumn::of(self::GROUP_ID),
                    IndexColumn::of(self::MEMBER),
                    IndexColumn::prefixed(self::VALUE_TEXT, 64),
                ),
            ],
            engine: Engine::InnoDB,
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

    public static function groupIdColumn(): string
    {
        return self::GROUP_ID;
    }

    public static function addressColumn(): string
    {
        return self::ADDRESS;
    }

    public static function memberColumn(): string
    {
        return self::MEMBER;
    }

    public static function textColumn(): string
    {
        return self::VALUE_TEXT;
    }

    public static function intColumn(): string
    {
        return self::VALUE_INT;
    }

    public static function decColumn(): string
    {
        return self::VALUE_DEC;
    }

    public static function dateColumn(): string
    {
        return self::VALUE_DATE;
    }

    /**
     * The value column that holds one leaf's canonical value. The leaves
     * table carries the same four value columns as the values table, so the
     * type-to-column grammar is that table's one map: a leaf write and the
     * member-qualified leaf query resolve through the same column, whatever
     * the type, and the two maps cannot drift apart again.
     */
    public static function columnFor(FieldType $type): ?string
    {
        return FieldValuesTable::columnFor($type);
    }
}
