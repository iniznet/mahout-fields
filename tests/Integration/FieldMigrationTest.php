<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\BooleanField;
use Iniznet\Mahout\Fields\DateField;
use Iniznet\Mahout\Fields\DecimalField;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\FieldReader;
use Iniznet\Mahout\Fields\FieldRegistry;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\Internal\MetaStorage;
use Iniznet\Mahout\Fields\Internal\TableStorage;
use Iniznet\Mahout\Fields\MigrateFieldMetaToTable;
use Iniznet\Mahout\Fields\MigrateFieldTableToMeta;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\PersonalData;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * Deliverable 4: the two migration directions, both through the adapters,
 * both bounded, both value-exact on read-back (cast values, not raw bytes --
 * value_dec's 3.500000 becomes 3.5 on the way back, and that is recorded).
 *
 * @internal
 */
final class FieldMigrationTest extends TestCase
{
    public function testTheMigrationNamesCarryTheFieldId(): void
    {
        $registry = new FieldRegistry();
        $registry->register($this->tableGroup());

        self::assertSame(
            'mahout/fields/meta_to_table/fixture_text',
            (new MigrateFieldMetaToTable($registry, $this->gateway, $this->connection(), 'fixture_text'))->name(),
        );
        self::assertSame(
            'mahout/fields/table_to_meta/fixture_text',
            (new MigrateFieldTableToMeta($registry, $this->gateway, $this->connection(), 'fixture_text'))->name(),
        );
    }

    public function testMetaToTableMovesEveryScalarValueExactly(): void
    {
        $postId = $this->postId();

        // The data was written while the field was Meta-bound.
        $this->registry->register($this->metaGroup());
        $object = ObjectRef::post($postId);
        foreach (['fixture_text' => 'The Telling', 'fixture_integer' => 7, 'fixture_decimal' => '3.5', 'fixture_date' => '2024-06-01', 'fixture_boolean' => true] as $fieldId => $value) {
            $this->writer->set($fieldId, $object, $value);
        }

        // The declaration moved to Table; the data has not yet. The
        // migration is per field: one instance per field id, the ledger's
        // UNIQUE name carrying that id.
        foreach (['fixture_text', 'fixture_integer', 'fixture_decimal', 'fixture_date', 'fixture_boolean'] as $fieldId) {
            (new MigrateFieldMetaToTable($this->registry, $this->gateway, $this->connection(), $fieldId))->up();
        }

        foreach (['fixture_text', 'fixture_integer', 'fixture_decimal', 'fixture_date', 'fixture_boolean'] as $fieldId) {
            self::assertNull($this->metaRaw($fieldId, $postId), $fieldId.' meta row is gone after the move');
        }

        self::assertSame('The Telling', $this->tableReader()->value('fixture_text', $object));
    }

    public function testTableToMetaMovesEveryScalarValueExactly(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());
        $object = ObjectRef::post($postId);
        $this->writer->set('fixture_text', $object, 'Rocannon\'s World');
        $this->writer->set('fixture_integer', $object, 7);
        $this->writer->set('fixture_decimal', $object, '3.5');
        $this->writer->set('fixture_date', $object, '2024-06-01');
        $this->writer->set('fixture_boolean', $object, true);

        foreach (['fixture_text', 'fixture_integer', 'fixture_decimal', 'fixture_date', 'fixture_boolean'] as $fieldId) {
            (new MigrateFieldTableToMeta($this->registry, $this->gateway, $this->connection(), $fieldId))->up();
        }

        self::assertNull($this->rawValueRow('fixture_text', $postId), 'the value row is gone after the move');
        self::assertSame("Rocannon's World", $this->metaReader()->value('fixture_text', $object));
        self::assertSame(7, $this->metaReader()->value('fixture_integer', $object));
        self::assertSame(3.5, $this->metaReader()->value('fixture_decimal', $object), 'value_dec is value-exact, not byte-exact');
        self::assertSame('2024-06-01', $this->metaReader()->value('fixture_date', $object));
        self::assertTrue($this->metaReader()->value('fixture_boolean', $object));
    }

    public function testMetaToTableMovesRepeaterItemsInPositionOrder(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->metaRepeaterGroup());
        $object = ObjectRef::post($postId);
        $this->writer->setItems('fixture_items', $object, ['paperback', 'hardcover']);

        (new MigrateFieldMetaToTable($this->registry, $this->gateway, $this->connection(), 'fixture_items'))->up();

        self::assertNull($this->metaRaw('fixture_items', $postId));
        self::assertSame(['paperback', 'hardcover'], $this->repeaterTableReader()->items('fixture_items', $object));
    }

    public function testTableToMetaMovesRepeaterItemsBackIntoTheVersionedPayload(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableRepeaterGroup());
        $object = ObjectRef::post($postId);
        $this->writer->setItems('fixture_items', $object, ['hardcover', 'paperback']);

        (new MigrateFieldTableToMeta($this->registry, $this->gateway, $this->connection(), 'fixture_items'))->up();

        self::assertNull($this->rawItemRow('fixture_items', $postId, 0));
        self::assertSame(['hardcover', 'paperback'], $this->repeaterMetaReader()->items('fixture_items', $object));
    }

    public function testEachDirectionReversesTheOther(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->metaGroup());
        $object = ObjectRef::post($postId);
        $this->writer->set('fixture_text', $object, 'Planet of Exile');

        (new MigrateFieldMetaToTable($this->registry, $this->gateway, $this->connection(), 'fixture_text'))->up();
        (new MigrateFieldMetaToTable($this->registry, $this->gateway, $this->connection(), 'fixture_text'))->down();

        self::assertSame('Planet of Exile', $this->metaReader()->value('fixture_text', $object));
        self::assertNull($this->rawValueRow('fixture_text', $postId));
    }

    public function testAUserContextFieldIsRefusedLoudly(): void
    {
        $this->registry->register($this->userGroup());

        $this->expectException(InvalidFieldContext::class);
        (new MigrateFieldMetaToTable($this->registry, $this->gateway, $this->connection(), 'fixture_text'))->up();
    }

    public function testAMigrationNeitherWritesNorDeletesTheMirror(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());
        $object = ObjectRef::post($postId);
        $this->writer->set('fixture_text', $object, 'before the move');

        $before = $this->mirror->payloadOf($object, 'fixture_group');
        self::assertNotNull($before, 'the single-field write seeded the mirror');

        // The move changes where the value lives, never what the mirror
        // records: the next group save refreshes it, reads never consult it.
        (new MigrateFieldTableToMeta($this->registry, $this->gateway, $this->connection(), 'fixture_text'))->up();
        (new MigrateFieldMetaToTable($this->registry, $this->gateway, $this->connection(), 'fixture_text'))->up();

        self::assertSame($before, $this->mirror->payloadOf($object, 'fixture_group'), 'the migration leaves the mirror byte-identical');
    }

    private function metaGroup(): FieldGroup
    {
        return new FieldGroup('fixture_group', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Meta),
            new IntegerField('fixture_integer', StorageTarget::Meta),
            new DecimalField('fixture_decimal', StorageTarget::Meta),
            new DateField('fixture_date', StorageTarget::Meta),
            new BooleanField('fixture_boolean', StorageTarget::Meta),
        ]);
    }

    private function tableGroup(): FieldGroup
    {
        return new FieldGroup('fixture_group', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table),
            new IntegerField('fixture_integer', StorageTarget::Table),
            new DecimalField('fixture_decimal', StorageTarget::Table),
            new DateField('fixture_date', StorageTarget::Table),
            new BooleanField('fixture_boolean', StorageTarget::Table),
        ]);
    }

    private function metaRepeaterGroup(): FieldGroup
    {
        return new FieldGroup('fixture_group', ObjectContext::Post, [
            new RepeaterField('fixture_items', StorageTarget::Meta, new TextField('fixture_item', StorageTarget::Carried), 5),
        ]);
    }

    private function tableRepeaterGroup(): FieldGroup
    {
        return new FieldGroup('fixture_group', ObjectContext::Post, [
            new RepeaterField('fixture_items', StorageTarget::Table, new TextField('fixture_item', StorageTarget::Carried), 5),
        ]);
    }

    private function userGroup(): FieldGroup
    {
        return new FieldGroup('fixture_user', ObjectContext::User, [
            new TextField('fixture_text', StorageTarget::Meta, PersonalData::notPersonal('the migration fixture carries no user data')),
        ]);
    }

    /** A reader over the same registry with the Table declarations. */
    private function tableReader(): FieldReader
    {
        return $this->readerFor($this->tableGroup());
    }

    private function metaReader(): FieldReader
    {
        return $this->readerFor($this->metaGroup());
    }

    private function repeaterTableReader(): FieldReader
    {
        return $this->readerFor($this->tableRepeaterGroup());
    }

    private function repeaterMetaReader(): FieldReader
    {
        return $this->readerFor($this->metaRepeaterGroup());
    }

    private function readerFor(FieldGroup $group): FieldReader
    {
        $registry = new FieldRegistry();
        $registry->register($group);
        $meta = new MetaStorage();
        $table = new TableStorage($this->gateway, $this->valuesTable, $this->itemsTable);

        return new FieldReader($registry, $meta, $table, $this->mirror);
    }

    /**
     * The raw stored meta value of a field, or null when absent.
     */
    private function metaRaw(string $fieldId, int $postId): string|int|float|bool|null
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT meta_value FROM '.$wpdb->postmeta.' WHERE post_id = %d AND meta_key = %s LIMIT 1',
            $postId,
            $fieldId,
        ), ARRAY_A);

        return \is_array($row) ? $row['meta_value'] : null;
    }
}
