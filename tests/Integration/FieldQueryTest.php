<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Db\Exception\UnboundedStatement;
use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\FieldQuery;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\Operator;
use Iniznet\Mahout\Fields\OrderDirection;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\Fixtures\RecordingSqlConnection;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * The bounded field query builder: every statement is composed from declared
 * identifiers, every value is a placeholder, every statement carries an
 * explicit LIMIT except the aggregate, and the two-phase pattern short-circuits
 * before core's query when the builder found nothing.
 *
 * @internal
 */
final class FieldQueryTest extends TestCase
{
    private RecordingSqlConnection $recording;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recording = new RecordingSqlConnection($this->connection());
    }

    public function testPostIdsReturnsTheObjectsWhoseStoredValueCompares(): void
    {
        $this->registry->register($this->group());
        $alpha = $this->postId();
        $beta = $this->postId();
        $gamma = $this->postId();
        $this->writer->set('fixture_integer', ObjectRef::post($alpha), 10);
        $this->writer->set('fixture_integer', ObjectRef::post($beta), 50);
        $this->writer->set('fixture_integer', ObjectRef::post($gamma), 90);

        $ids = $this->query()->postIds('fixture_integer', Operator::GreaterThan, 20, 10);

        self::assertSame([$beta, $gamma], $ids);
    }

    public function testPostIdsIsBoundedByItsLimit(): void
    {
        $this->registry->register($this->group());
        foreach ([10, 20, 30] as $value) {
            $this->writer->set('fixture_integer', ObjectRef::post($this->postId()), $value);
        }

        $ids = $this->query()->postIds('fixture_integer', Operator::GreaterThan, 0, 2);

        self::assertCount(2, $ids, 'the statement carries LIMIT n, always');
    }

    public function testTheStatementIsComposedFromDeclaredIdentifiersOnly(): void
    {
        $this->registry->register($this->group());
        $this->query()->postIds('fixture_integer', Operator::Equals, 1, 5);

        $statement = $this->recording->statements[0] ?? '';

        self::assertStringContainsString('SELECT', $statement);
        self::assertStringContainsString($this->valuesTableName(), $statement, 'the table is read from the declaration, never from caller text');
        self::assertStringContainsString('field_id', $statement);
        self::assertStringContainsString('LIMIT %d', $statement);
        self::assertStringNotContainsString('DROP', $statement);
    }

    public function testCountAggregatesOverTheIndexedColumn(): void
    {
        $this->registry->register($this->group());
        $this->writer->set('fixture_integer', ObjectRef::post($this->postId()), 5);
        $this->writer->set('fixture_integer', ObjectRef::post($this->postId()), 5);
        $this->writer->set('fixture_integer', ObjectRef::post($this->postId()), 6);

        self::assertSame(2, $this->query()->count('fixture_integer', Operator::Equals, 5));
        self::assertSame(3, $this->query()->count('fixture_integer', Operator::GreaterThanOrEqual, 0));
    }

    public function testOrderedIdsReturnsIndexOrderWithNoSecondSort(): void
    {
        $this->registry->register($this->group());
        $low = $this->postId();
        $high = $this->postId();
        $this->writer->set('fixture_integer', ObjectRef::post($low), 1);
        $this->writer->set('fixture_integer', ObjectRef::post($high), 1000);

        self::assertSame([$low, $high], $this->query()->orderedIds('fixture_integer', OrderDirection::Ascending, 10));
        self::assertSame([$high, $low], $this->query()->orderedIds('fixture_integer', OrderDirection::Descending, 10));
    }

    public function testALimitBelowOneIsUnrepresentable(): void
    {
        $this->registry->register($this->group());

        try {
            $this->query()->postIds('fixture_integer', Operator::Equals, 1, 0);
            self::fail('a zero limit must be refused before a statement exists');
        } catch (UnboundedStatement) {
            self::addToAssertionCount(1);
        }

        $this->assertSame([], $this->recording->statements, 'the refusal happens before any statement is built');
    }

    public function testAMetaBoundFieldIsNotQueryableThroughTheValueTable(): void
    {
        $this->registry->register(new FieldGroup('fixture_meta_group', ObjectContext::Post, [
            new TextField('fixture_meta_field', StorageTarget::Meta),
        ]));

        try {
            $this->query()->postIds('fixture_meta_field', Operator::Equals, 'x', 5);
            self::fail('a Meta-bound field has no row to query');
        } catch (InvalidStorageCombination $refusal) {
            self::assertSame('fixture_meta_field', $refusal->fieldId());
        }
    }

    public function testARepeaterHasNoValueColumnToCompare(): void
    {
        $this->registry->register(new FieldGroup('fixture_repeater_group', ObjectContext::Post, [
            new RepeaterField('fixture_repeater', StorageTarget::Table, new TextField('fixture_item', StorageTarget::Carried)),
        ]));

        try {
            $this->query()->orderedIds('fixture_repeater', OrderDirection::Ascending, 5);
            self::fail('a repeater is queried through its items, never the value table');
        } catch (InvalidFieldDefinition $refusal) {
            self::assertSame('fixture_repeater', $refusal->fieldId());
        }
    }

    public function testTheTwoPhasePatternShortCircuitsOnAnEmptyIdList(): void
    {
        $this->registry->register($this->group());

        $ids = $this->query()->postIds('fixture_integer', Operator::Equals, 424242, 10);
        $query = [] === $ids ? null : new \WP_Query(['post__in' => $ids, 'orderby' => 'post__in', 'fields' => 'ids', 'no_found_rows' => true]);

        self::assertSame([], $ids);
        self::assertNull($query, 'an empty id list never reaches core\'s query');
    }

    public function testTheTwoPhasePatternOrdersByTheBuilderResult(): void
    {
        $this->registry->register($this->group());
        $first = $this->postId();
        $second = $this->postId();
        $this->writer->set('fixture_integer', ObjectRef::post($first), 100);
        $this->writer->set('fixture_integer', ObjectRef::post($second), 5);

        $ids = $this->query()->orderedIds('fixture_integer', OrderDirection::Ascending, 10);
        $query = new \WP_Query(['post__in' => $ids, 'orderby' => 'post__in', 'fields' => 'ids', 'no_found_rows' => true, 'posts_per_page' => 10]);

        self::assertSame($ids, array_map('intval', $query->posts), 'core returns the objects in the builder\'s order, not id order');
    }

    // ------------------------------------------------------------------

    private function query(): FieldQuery
    {
        return new FieldQuery($this->registry, $this->recording, $this->valuesTable);
    }

    private function group(): FieldGroup
    {
        return new FieldGroup('fixture_group', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table),
            new IntegerField('fixture_integer', StorageTarget::Table),
        ]);
    }
}
