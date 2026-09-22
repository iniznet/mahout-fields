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
use Iniznet\Mahout\Fields\FieldItemsTable;
use Iniznet\Mahout\Fields\FieldValuesTable;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RegisteredField;
use Iniznet\Mahout\Fields\RepeaterCodec;
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
        $this->table = new TableStorage($gateway, $this->valuesTable(), $this->itemsTable());
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

        $select = 'SELECT '.self::META_ID_COLUMN.', '.self::META_OBJECT_COLUMN
            .' FROM '.$this->metaTable()->quoted()
            .' WHERE '.self::META_KEY_COLUMN.' = %s'
            .' AND '.self::META_ID_COLUMN.' > %d'
            .' ORDER BY '.self::META_ID_COLUMN
            .' LIMIT '.self::CHUNK;

        $cursor = 0;

        while (true) {
            $rows = $this->connection->rowsPrepared($select, $fieldId, $cursor);

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
            // position, so a chunk boundary can never split a payload.
            while (true) {
                $rows = $this->gateway->select(GatewayQuery::bounded(
                    $this->itemsKey($fieldId),
                    1,
                ));

                if ([] === $rows) {
                    break;
                }

                $this->gateway->transactional(function () use ($field, $rows, &$moved): void {
                    $this->assertKind($rows[0], $field->id);
                    $object = ObjectRef::post((int) $rows[0]->value(FieldItemsTable::objectIdColumn()));

                    $items = [];
                    foreach ($this->table->readItems($field, $object) as $item) {
                        if (null !== $item['value']) {
                            $items[] = $item['value'];
                        }
                    }

                    if ([] !== $items) {
                        $this->meta->write($field, $object, RepeaterCodec::encode($items));
                        ++$moved;
                    }

                    // The keyed delete removes every position, including the
                    // row the discovery select served, so the next select
                    // advances to the next object.
                    $this->table->deleteItems($field, $object);
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
     * The bounded discovery key on the items table: an equality on field_id,
     * which the items table's own field_id column names and its primary key
     * serves. The gateway refuses anything unbounded.
     */
    private function itemsKey(string $fieldId): Row
    {
        return Row::of($this->itemsTable(), [
            FieldItemsTable::fieldIdColumn() => $fieldId,
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

    private function writeTableFromMeta(Field $field, ObjectRef $object, string|int|float|bool $raw): void
    {
        if ($field instanceof RepeaterField) {
            $items = [];
            foreach (RepeaterCodec::decode((string) $raw) as $item) {
                if (\is_array($item)) {
                    throw InvalidFieldWrite::recordedItems($field->id);
                }

                $items[] = $item;
            }

            if ([] === $items) {
                return;
            }

            $this->table->writeItems($field, $object, $items);

            return;
        }

        $value = $field->cast($raw);

        if (null === $value) {
            throw InvalidFieldWrite::unreadableMeta($field->id);
        }

        $this->table->write($field, $object, $value);
    }

    private function assertKind(Row $row, string $fieldId): void
    {
        $kind = (int) $row->value(FieldValuesTable::objectKindColumn());

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

    private function itemsTable(): Table
    {
        return FieldItemsTable::table($this->connection->prefix(), $this->connection->charsetCollate());
    }
}
