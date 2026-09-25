<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Fields\BooleanField;
use Iniznet\Mahout\Fields\ChoiceField;
use Iniznet\Mahout\Fields\DateField;
use Iniznet\Mahout\Fields\DecimalField;
use Iniznet\Mahout\Fields\EmailField;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextAreaField;
use Iniznet\Mahout\Fields\TextField;
use Iniznet\Mahout\Fields\UrlField;

/**
 * Deliverable 4: the same round-trip through the generic value table, with
 * the ROW SHAPE asserted -- exact columns, exact values, sibling value
 * columns left null.
 *
 * @internal
 */
final class TableRoundTripTest extends TestCase
{
    private const string VALUE_ROW_COLUMNS = 'object_kind,object_id,field_id,value_text,value_int,value_dec,value_date';

    public function testEveryFieldTypeRoundTripsThroughTheValueTable(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        foreach ($this->scalarCases() as $fieldId => [$write, $expected]) {
            $this->writer->set($fieldId, ObjectRef::post($postId), $write);
            self::assertSame($expected, $this->reader->value($fieldId, ObjectRef::post($postId)), $fieldId);
        }
    }

    public function testATextRowHoldsTheDeclaredRowShape(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        $this->writer->set('fixture_text', ObjectRef::post($postId), 'The Left Hand of Darkness');

        $row = $this->rawValueRow('fixture_text', $postId);

        self::assertNotNull($row, 'the value row must exist');
        self::assertSame(
            ['object_kind', 'object_id', 'field_id', 'value_text', 'value_int', 'value_dec', 'value_date'],
            array_keys($row),
        );
        self::assertSame('1', $row['object_kind']);
        self::assertSame((string) $postId, $row['object_id']);
        self::assertSame('fixture_text', $row['field_id']);
        self::assertSame('The Left Hand of Darkness', $row['value_text']);
        self::assertNull($row['value_int']);
        self::assertNull($row['value_dec']);
        self::assertNull($row['value_date']);
    }

    public function testAnIntegerRowUsesTheIntColumnAndLeavesTheOthersNull(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        $this->writer->set('fixture_integer', ObjectRef::post($postId), '42');

        $row = $this->rawValueRow('fixture_integer', $postId);

        self::assertNotNull($row);
        self::assertSame('42', $row['value_int']);
        self::assertNull($row['value_text']);
        self::assertNull($row['value_dec']);
        self::assertNull($row['value_date']);
    }

    public function testADecimalAndADateLandInTheirDeclaredColumns(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        $this->writer->set('fixture_decimal', ObjectRef::post($postId), '3.5');
        $this->writer->set('fixture_date', ObjectRef::post($postId), '2024-06-01');

        $decimal = $this->rawValueRow('fixture_decimal', $postId);
        self::assertNotNull($decimal);
        self::assertSame('3.500000', $decimal['value_dec']);
        self::assertNull($decimal['value_text']);

        $date = $this->rawValueRow('fixture_date', $postId);
        self::assertNotNull($date);
        self::assertSame('2024-06-01 00:00:00', $date['value_date']);
    }

    public function testRewritingATextFieldLeavesNoStaleValueInASiblingColumn(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        $this->writer->set('fixture_integer', ObjectRef::post($postId), 42);
        $this->writer->set('fixture_text', ObjectRef::post($postId), 'rewritten');

        $row = $this->rawValueRow('fixture_integer', $postId);
        self::assertNotNull($row);
        self::assertSame('42', $row['value_int']);
        self::assertNull($row['value_text']);
    }

    public function testTheValueTablePrimaryKeyIsObjectKindObjectIdFieldId(): void
    {
        $this->registry->register($this->tableGroup());

        $indexes = $this->indexNames($this->valuesTable);

        self::assertContains('PRIMARY', $indexes);
        self::assertContains('field_text', $indexes);
        self::assertContains('field_int', $indexes);
        self::assertContains('field_dec', $indexes);
        self::assertContains('field_date', $indexes);
    }

    public function testDeletingATableFieldRemovesItsRow(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        $this->writer->set('fixture_text', ObjectRef::post($postId), 'gone soon');
        self::assertNotNull($this->rawValueRow('fixture_text', $postId));

        $this->writer->delete('fixture_text', ObjectRef::post($postId));

        self::assertNull($this->rawValueRow('fixture_text', $postId));
        self::assertNull($this->reader->value('fixture_text', ObjectRef::post($postId)));
    }

    public function testWritingARepeaterThroughTheScalarPathIsRefused(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->tableGroup());

        $this->expectException(InvalidFieldWrite::class);
        $this->writer->set('fixture_repeater', ObjectRef::post($postId), '{"v":1,"items":[]}');
    }

    /**
     * @return array<string, array{0: string|int|float|bool, 1: string|int|float|bool}>
     */
    private function scalarCases(): array
    {
        return [
            'fixture_text' => ['The Left Hand of Darkness', 'The Left Hand of Darkness'],
            'fixture_textarea' => ["line one\nline two", "line one\nline two"],
            'fixture_email' => ['reader@example.org', 'reader@example.org'],
            'fixture_url' => ['https://example.org/series', 'https://example.org/series'],
            'fixture_choice' => ['hardcover', 'hardcover'],
            'fixture_integer' => ['42', 42],
            'fixture_decimal' => ['3.5', 3.5],
            'fixture_boolean' => ['0', false],
            'fixture_date' => ['2024-06-01', '2024-06-01'],
        ];
    }

    private function tableGroup(): FieldGroup
    {
        return new FieldGroup('fixture_table', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table),
            new TextAreaField('fixture_textarea', StorageTarget::Table),
            new EmailField('fixture_email', StorageTarget::Table),
            new UrlField('fixture_url', StorageTarget::Table),
            new ChoiceField('fixture_choice', StorageTarget::Table, ['paperback', 'hardcover']),
            new IntegerField('fixture_integer', StorageTarget::Table),
            new DecimalField('fixture_decimal', StorageTarget::Table),
            new BooleanField('fixture_boolean', StorageTarget::Table),
            new DateField('fixture_date', StorageTarget::Table),
            new RepeaterField('fixture_repeater', StorageTarget::Table, new TextField('fixture_item', StorageTarget::Carried)),
        ]);
    }
}
