<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Internal;

use Iniznet\Mahout\Db\Contracts\SqlConnection;
use Iniznet\Mahout\Db\Contracts\TableGateway;
use Iniznet\Mahout\Db\GatewayQuery;
use Iniznet\Mahout\Db\Identifier;
use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Db\Table;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Field;
use Iniznet\Mahout\Fields\FieldLeavesTable;
use Iniznet\Mahout\Fields\FieldValuesTable;
use Iniznet\Mahout\Fields\LeafAddress;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RegisteredField;
use Iniznet\Mahout\Fields\RepeaterField;

/**
 * Moves one field's stored values between the two storage targets, both ways.
 *
 * Every movement goes through the adapters -- never around them -- so the
 * canonicalisation is the field layer's and the read-back is the production
 * read path. The meta side is discovered by a keyset walk on meta_id, which
 * the meta_key index serves and the LIMIT bounds; the cursor strictly
 * advances. The table side is discovered by the gateway's bounded field_id
 * query, and each chunk deletes the rows it moved, so the next query advances
 * past them. A repeater is moved one object at a time, because a chunk
 * boundary may split an object's positions and a payload written from part of
 * an object's items would be data corruption.
 *
 * Only post-context fields move. Revisions are post-only, and the user- and
 * term-scoped collection and erasure paths are the privacy slice's work.
 *
 * Each chunk is one transaction on mahout-db's gateway. A failed row rolls the
 * chunk back; nothing is partially applied, nothing is retried, nothing is
 * substituted, and the original exception propagates to the runner. Every step
 * is idempotent, so an interrupted run is resumed by running it again.
 *
 * @internal
 */
final readonly class FieldMover
{
    private const int CHUNK = 500;

    private const string META_TABLE_SUFFIX = 'postmeta';

    private const string META_ID_COLUMN = 'meta_id';

    private const string META_KEY_COLUMN = 'meta_key';

    private const string META_OBJECT_COLUMN = 'post_id';

    private readonly MetaStorage $meta;

    private readonly TableStorage $table;

    public function __construct(
        private readonly FieldRegistry $registry,
        private readonly TableGateway $gateway,
        private readonly SqlConnection $connection,
    ) {
        $this->meta = new MetaStorage();
        $this->table = new TableStorage($gateway, $this->valuesTable(), $this->leavesTable());
    }

    /**
     * Copy every stored value from wp_postmeta into the declared tables, then
     * remove the meta rows.
     *
     * @throws InvalidFieldContext when the field's group is not post-context
     */
    public function metaToTable(string $fieldId): int
    {
        $field = $this->assertPostContext($fieldId)->field;
        $moved = 0;

        // A repeater's meta rows are its leaves, keyed by address, so the
        // discovery is a prefix match — no leading wildcard, served by the
        // meta_key index — while a scalar field matches its one key exactly.
        // A field id may carry underscores and `_` is a LIKE wildcard: the
        // id's own wildcards are escaped, so `fixture_items` never matches a
        // neighbouring key such as `fixtureXitems`, while the trailing
        // address wildcard stays literal.
        $repeater = $field instanceof RepeaterField;
        $match = $repeater ? 'LIKE %s' : '= %s';
        $pattern = $repeater ? \addcslashes($fieldId, '\\_%').'.%' : $fieldId;

        $select = 'SELECT '.self::META_ID_COLUMN.', '.self::META_OBJECT_COLUMN
            .' FROM '.$this->metaTable()->quoted()
            .' WHERE '.self::META_KEY_COLUMN.' '.$match
            .' AND '.self::META_ID_COLUMN.' > %d'
            .' ORDER BY '.self::META_ID_COLUMN
            .' LIMIT '.self::CHUNK;

        $cursor = 0;

        while (true) {
            $rows = $repeater
                ? $this->connection->rowsPrepared($select, $pattern, $cursor)
                : $this->connection->rowsPrepared($select, $fieldId, $cursor);

            if ([] === $rows) {
                break;
            }

            $ids = [];
            foreach ($rows as $row) {
                $cursor = \max($cursor, (int) ($row[self::META_ID_COLUMN] ?? 0));

                $id = (int) ($row[self::META_OBJECT_COLUMN] ?? 0);

                if ($id > 0 && !\in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }

            $this->gateway->transactional(function () use ($field, $ids, &$moved): void {
                foreach ($ids as $id) {
                    $object = ObjectRef::post($id);

                    if ($field instanceof RepeaterField) {
                        $this->writeLeavesFromMeta($field, $object);
                        $this->dropMetaLeaves($field, $object);
                        ++$moved;

                        continue;
                    }

                    $raw = $this->meta->read($field, $object);

                    if (null === $raw) {
                        continue;
                    }

                    $this->writeTableFromMeta($field, $object, $raw);
                    $this->meta->delete($field, $object);
                    ++$moved;
                }
            });
        }

        return $moved;
    }

    /**
     * Copy every stored row of the declared tables back into wp_postmeta,
     * then remove the rows. Returns the number of objects moved.
     *
     * @throws InvalidFieldContext when the field's group is not post-context
     */
    public function tableToMeta(string $fieldId): int
    {
        $field = $this->assertPostContext($fieldId)->field;
        $moved = 0;

        if ($field instanceof RepeaterField) {
            // One object per chunk: the discovery select serves one row, and
            // the keyed read and keyed delete cover the object's every
            // address, so a chunk boundary can never split a leaf set.
            while (true) {
                $rows = $this->gateway->select(GatewayQuery::bounded(
                    $this->leavesKey($fieldId),
                    1,
                ));

                if ([] === $rows) {
                    break;
                }

                $this->gateway->transactional(function () use ($field, $rows, &$moved): void {
                    $this->assertKind($rows[0], $field->id);
                    $object = ObjectRef::post((int) $rows[0]->value(FieldLeavesTable::objectIdColumn()));

                    foreach ($this->table->readLeaves($field, $object) as $leaf) {
                        if (null !== $leaf['raw']) {
                            $this->meta->writeLeaf($leaf['address'], $object, $leaf['raw']);
                        }
                    }

                    ++$moved;

                    // The keyed delete removes every address, including the
                    // row the discovery select served, so the next select
                    // advances to the next object.
                    $this->table->deleteLeaves($field, $object);
                });
            }

            return $moved;
        }

        while (true) {
            $rows = $this->gateway->select(GatewayQuery::bounded(
                $this->valueKey($fieldId),
                self::CHUNK,
            ));

            if ([] === $rows) {
                break;
            }

            $this->gateway->transactional(function () use ($field, $rows, &$moved): void {
                foreach ($rows as $row) {
                    $this->assertKind($row, $field->id);

                    $raw = $this->rawOf($row, $field);

                    if (null === $raw) {
                        continue;
                    }

                    $value = $field->cast($raw);

                    if (null === $value) {
                        throw InvalidFieldWrite::unreadableMeta($field->id);
                    }

                    $object = ObjectRef::post((int) $row->value(FieldValuesTable::objectIdColumn()));
                    $this->meta->write($field, $object, $value);
                    ++$moved;
                }

                $this->gateway->deleteMany($rows);
            });
        }

        return $moved;
    }

    /**
     * The bounded discovery key on the leaves table: an equality on group_id,
     * which the leaves table's own group_id column names and its primary key
     * serves. The gateway refuses anything unbounded.
     */
    private function leavesKey(string $fieldId): Row
    {
        return Row::of($this->leavesTable(), [
            FieldLeavesTable::groupIdColumn() => $fieldId,
        ]);
    }

    /**
     * The bounded discovery query for one field's value rows.
     */
    private function valueKey(string $fieldId): Row
    {
        return Row::of($this->valuesTable(), [
            FieldValuesTable::fieldIdColumn() => $fieldId,
        ]);
    }

    /**
     * The raw stored value of one value row, from its field type's column.
     */
    private function rawOf(Row $row, Field $field): string|int|null
    {
        $column = FieldValuesTable::columnFor($field->type());

        if (null === $column) {
            throw InvalidFieldWrite::jsonIntoItemsTable($field->id);
        }

        return $row->has($column) ? $row->value($column) : null;
    }

    /**
     * The Meta leaves of one repeater, canonicalised back through the member
     * field the address resolves to, written as one addressed leaf set.
     */
    private function writeLeavesFromMeta(RepeaterField $field, ObjectRef $object): void
    {
        $leaves = [];

        foreach ($this->meta->leaves($field->id, $object) as $address => $raw) {
            $parsed = LeafAddress::of((string) $address);
            $member = $this->memberFieldAt($field, (string) $address);
            $value = $member->cast($raw);

            if (null === $value) {
                continue;
            }

            $leaves[] = [
                'address' => (string) $address,
                'relative' => $parsed->relative,
                'member' => $parsed->member,
                'field' => $member,
                'value' => $value,
            ];
        }

        $this->table->writeLeaves($field, $object, $leaves);
    }

    private function dropMetaLeaves(RepeaterField $field, ObjectRef $object): void
    {
        foreach (array_keys($this->meta->leaves($field->id, $object)) as $stale) {
            $this->meta->deleteLeaf((string) $stale, $object);
        }
    }

    private function writeTableFromMeta(Field $field, ObjectRef $object, string|int|float|bool $raw): void
    {
        $value = $field->cast($raw);

        if (null === $value) {
            throw InvalidFieldWrite::unreadableMeta($field->id);
        }

        $this->table->write($field, $object, $value);
    }

    private function assertKind(Row $row, string $fieldId): void
    {
        $column = $row->has(FieldLeavesTable::objectKindColumn())
            ? FieldLeavesTable::objectKindColumn()
            : FieldValuesTable::objectKindColumn();

        $kind = (int) $row->value($column);

        if ($kind !== ObjectKind::Post->value) {
            throw InvalidFieldWrite::foreignObjectKind($fieldId);
        }
    }

    private function assertPostContext(string $fieldId): RegisteredField
    {
        $registered = $this->registry->resolve($fieldId);

        if (ObjectContext::Post !== $registered->group->context) {
            throw InvalidFieldContext::migrationUnsupported($fieldId, $registered->group->context->value);
        }

        return $registered;
    }

    private function metaTable(): Identifier
    {
        return Identifier::prefixed($this->connection->prefix(), self::META_TABLE_SUFFIX);
    }

    private function valuesTable(): Table
    {
        return FieldValuesTable::table($this->connection->prefix(), $this->connection->charsetCollate());
    }

    private function leavesTable(): Table
    {
        return FieldLeavesTable::table($this->connection->prefix(), $this->connection->charsetCollate());
    }

    /**
     * The member field one leaf's address resolves to, walked against the
     * declaration: positions choose items, member names choose fields, and a
     * nested repeater member becomes the walked level. Member ids are unique
     * across the subtree, so the walk is unambiguous.
     */
    private function memberFieldAt(RepeaterField $root, string $fullAddress): Field
    {
        $address = LeafAddress::of($fullAddress);
        $current = $root;

        if ('' === $address->relative) {
            throw InvalidFieldWrite::badRepeaterAddress($root->id);
        }

        $segments = explode('.', $address->relative);
        $index = 0;

        while (true) {
            // A position is present at every level; consume it.
            if (!isset($segments[$index]) || !ctype_digit($segments[$index])) {
                throw InvalidFieldWrite::badRepeaterAddress($root->id);
            }

            ++$index;

            if (!isset($segments[$index])) {
                if (!$current->item instanceof Field) {
                    throw InvalidFieldWrite::badRepeaterAddress($root->id);
                }

                return $current->item;
            }
            $member = array_find($current->members(), fn ($candidate) => $candidate->id === $segments[$index]);

            if (null === $member) {
                throw InvalidFieldWrite::badRepeaterAddress($root->id);
            }

            ++$index;

            if (!$member instanceof RepeaterField) {
                if (isset($segments[$index])) {
                    throw InvalidFieldWrite::badRepeaterAddress($root->id);
                }

                return $member;
            }

            $current = $member;
        }
    }
}
