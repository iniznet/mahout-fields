<?php

/**
 * The claim that makes the storage target a storage decision rather than a
 * performance one: reading a page of Table-stored fields costs one statement, not
 * one per field per row. Each case is proved by counting the statements the real
 * gateway issues, and by asserting the primed answers are the unprimed answers.
 *
 * @internal
 */
declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Integration;

use Iniznet\Mahout\Db\Row;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\FieldLeavesTable;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

final class FieldPrimeTest extends TestCase
{
    private const int ROWS = 5;

    public function testAPrimeCostsOneStatementAndAnswersEveryFieldFromMemory(): void
    {
        $refs = $this->registerAndFill();

        $primed = $this->statements(function () use ($refs): void {
            $this->reader->prime($refs);
        });

        self::assertSame(1, $primed, 'one statement per kind for the whole page.');

        $reads = $this->statements(function () use ($refs): void {
            foreach ($refs as $ref) {
                foreach (['prime_one', 'prime_two', 'prime_three'] as $fieldId) {
                    self::assertNotNull($this->reader->value($fieldId, $ref), $fieldId);
                }
            }
        });

        self::assertSame(0, $reads, 'fifteen field reads after the prime cost no statement.');
    }

    public function testUnprimedReadsReturnTheSameValuesAtOneStatementPerField(): void
    {
        $refs = $this->registerAndFill();

        $statements = $this->statements(function () use ($refs): void {
            foreach ($refs as $ref) {
                foreach (['prime_one', 'prime_two', 'prime_three'] as $fieldId) {
                    self::assertNotNull($this->reader->value($fieldId, $ref), $fieldId);
                }
            }
        });

        self::assertSame(
            self::ROWS * 3,
            $statements,
            'without the prime the page costs one primary-key read per field per row — the multiplication the prime exists to remove.',
        );
    }

    public function testThePrimedAnswersAreTheUnprimedAnswers(): void
    {
        $refs = $this->registerAndFill();

        $before = [];

        foreach ($refs as $ref) {
            foreach (['prime_one', 'prime_two', 'prime_three'] as $fieldId) {
                $before[$ref->id.':'.$fieldId] = $this->reader->value($fieldId, $ref);
            }
        }

        // Nothing was filed above: an unprimed read answers from its own
        // primary-key statement and leaves the store untouched, so the same reader
        // still has a cold store to fill here.
        $this->reader->prime($refs);

        foreach ($refs as $ref) {
            foreach (['prime_one', 'prime_two', 'prime_three'] as $fieldId) {
                self::assertSame(
                    $before[$ref->id.':'.$fieldId],
                    $this->reader->value($fieldId, $ref),
                    $fieldId.' on '.$ref->id,
                );
            }
        }
    }

    public function testAnObjectWithNoRowsIsPrimedAsAbsentAndCostsNothingToRead(): void
    {
        $this->registry->register($this->group());
        $empty = ObjectRef::post($this->postId());

        $this->reader->prime([$empty]);

        $statements = $this->statements(function () use ($empty): void {
            self::assertNull($this->reader->value('prime_one', $empty));
            self::assertNull($this->reader->value('prime_two', $empty));
        });

        self::assertSame(0, $statements, 'absence is recorded by the prime, not rediscovered field by field.');
    }

    public function testAReadingOfOnlyMetaAnchoredFieldsIssuesNoPrimeStatement(): void
    {
        $this->registry->register(new FieldGroup('prime_meta', ObjectContext::Post, [
            new TextField('meta_only', StorageTarget::Meta),
        ]));

        // The post is created outside the window: writing it costs real statements,
        // and the number under test is what the prime adds, not what the fixture
        // costs.
        $ref = ObjectRef::post($this->postId());

        $statements = $this->statements(function () use ($ref): void {
            $this->reader->prime([$ref]);
        });

        self::assertSame(0, $statements, 'a theme that anchors nothing to the value table pays nothing for the prime.');
    }

    public function testPrimingAnObjectTwiceIssuesTheSecondReadForNobody(): void
    {
        $refs = $this->registerAndFill();

        $this->reader->prime($refs);

        $again = $this->statements(function () use ($refs): void {
            $this->reader->prime($refs);
        });

        self::assertSame(0, $again, 'an already-filed object is not fetched twice.');
    }

    /**
     * A page of repeaters whose declaration bounds them: one statement brings home
     * every leaf, and the reads that follow cost nothing. This is the half of
     * ADR-0011 that a host can state, and it is asserted by count rather than by
     * description because the difference between one read and five is the whole
     * claim.
     */
    public function testABoundedRepeaterCostsOneStatementForTheWholePage(): void
    {
        $refs = $this->registerAndFillRepeater(2);

        $primed = $this->statements(function () use ($refs): void {
            $this->reader->prime($refs);
        });

        self::assertSame(1, $primed, 'one leaves statement for five objects, not five.');

        $reads = $this->statements(function () use ($refs): void {
            foreach ($refs as $index => $ref) {
                self::assertSame(
                    ['role '.$index.'.0', 'role '.$index.'.1'],
                    $this->reader->items('prime_credits', $ref),
                    (string) $ref->id,
                );
            }
        });

        self::assertSame(0, $reads, 'ten repeater reads after the prime cost no statement.');
    }

    /**
     * The other half of the same rule: a repeater that declares no bound cannot be
     * primed, because the only ceiling available would be a guess and a guess filed
     * as a bound truncates the page it was meant to speed up.
     */
    public function testAnUnboundedRepeaterStillCostsOneStatementPerObject(): void
    {
        $refs = $this->registerAndFillRepeater(null);

        $primed = $this->statements(function () use ($refs): void {
            $this->reader->prime($refs);
        });

        self::assertSame(0, $primed, 'an unbounded repeater issues no leaves read: there is nothing to bound a LIMIT with.');

        $reads = $this->statements(function () use ($refs): void {
            foreach ($refs as $ref) {
                self::assertCount(2, $this->reader->items('prime_credits', $ref));
            }
        });

        self::assertSame(
            self::ROWS,
            $reads,
            'the page pays one keyed read per object, which is the cost a host buys out of by declaring a bound.',
        );
    }

    /**
     * Absence is the normal case on a listing, and a page that rediscovered it per
     * object would spend one statement to learn that no office has any.
     */
    public function testARepeaterWithNoItemsIsPrimedAsAbsent(): void
    {
        $this->registry->register(new FieldGroup('prime_repeater', ObjectContext::Post, [
            new RepeaterField('prime_credits', StorageTarget::Table, new TextField('prime_role', StorageTarget::Carried), 2),
        ]));

        $ref = ObjectRef::post($this->postId());

        $this->reader->prime([$ref]);

        $statements = $this->statements(function () use ($ref): void {
            self::assertSame([], $this->reader->items('prime_credits', $ref));
        });

        self::assertSame(0, $statements, 'a repeater with no items is recorded absent, not rediscovered item by item.');
    }

    /**
     * The prime is an optimisation of a read, never a different read: the same page
     * answers identically with the store full and with the store empty.
     */
    public function testThePrimedLeavesAreTheUnprimedLeaves(): void
    {
        $refs = $this->registerAndFillRepeater(2);

        $before = [];

        foreach ($refs as $ref) {
            $before[$ref->id] = $this->reader->items('prime_credits', $ref);
        }

        $this->reader->prime($refs);

        foreach ($refs as $ref) {
            self::assertSame($before[$ref->id], $this->reader->items('prime_credits', $ref), (string) $ref->id);
        }
    }

    /**
     * A bound the data does not obey - a migration that widened a group, a write that
     * bypassed the field layer - is met by declining the prime, not by filing a
     * partial group. Nothing is silently lost, and the row that broke the bound is
     * still read.
     */
    public function testAPrimeDeclinesWhenTheStoredRowsExceedItsBound(): void
    {
        $refs = $this->registerAndFillRepeater(1, 1);

        // One leaf past a bound of one item per object, written straight through the
        // gateway: the shape a migration that widened a group leaves behind, and the
        // only case where the declared ceiling is not the stored one.
        $this->gateway->insert(Row::of($this->leavesTable, [
            FieldLeavesTable::objectKindColumn() => ObjectKind::Post->value,
            FieldLeavesTable::objectIdColumn() => $refs[0]->id,
            FieldLeavesTable::groupIdColumn() => 'prime_credits',
            FieldLeavesTable::addressColumn() => '7',
            FieldLeavesTable::memberColumn() => 'prime_role',
            FieldLeavesTable::textColumn() => 'role 0.7',
        ]));

        $this->reader->prime($refs);

        $reads = $this->statements(function () use ($refs): void {
            foreach ($refs as $ref) {
                $this->reader->items('prime_credits', $ref);
            }
        });

        self::assertSame(self::ROWS, $reads, 'the prime declined, so every object kept the keyed read it has always had.');

        self::assertSame(
            ['role 0.0', 'role 0.7'],
            $this->reader->items('prime_credits', $refs[0]),
            'the row that broke the bound is read, not truncated away by a LIMIT that trusted the declaration.',
        );
    }

    /**
     * @return list<ObjectRef>
     */
    private function registerAndFillRepeater(?int $maxItems, int $items = 2): array
    {
        $this->registry->register(new FieldGroup('prime_repeater', ObjectContext::Post, [
            new RepeaterField('prime_credits', StorageTarget::Table, new TextField('prime_role', StorageTarget::Carried), $maxItems),
        ]));

        $refs = [];

        for ($row = 0; $row < self::ROWS; ++$row) {
            $ref = ObjectRef::post($this->postId());
            $refs[] = $ref;

            $values = [];

            for ($item = 0; $item < $items; ++$item) {
                $values[] = 'role '.$row.'.'.$item;
            }

            $this->writer->setItems('prime_credits', $ref, $values);
        }

        return $refs;
    }

    /**
     * @return list<ObjectRef>
     */
    private function registerAndFill(): array
    {
        $this->registry->register($this->group());

        $refs = [];

        for ($row = 0; $row < self::ROWS; ++$row) {
            $ref = ObjectRef::post($this->postId());
            $refs[] = $ref;

            $this->writer->set('prime_one', $ref, 10 + $row);
            $this->writer->set('prime_two', $ref, 'text '.$row);
            $this->writer->set('prime_three', $ref, 100);
        }

        return $refs;
    }

    private function group(): FieldGroup
    {
        return new FieldGroup('prime_page', ObjectContext::Post, [
            new IntegerField('prime_one', StorageTarget::Table),
            new TextField('prime_two', StorageTarget::Table),
            new IntegerField('prime_three', StorageTarget::Table),
        ]);
    }

    /**
     * @param callable(): void $work
     */
    private function statements(callable $work): int
    {
        global $wpdb;

        $before = \count((array) ($wpdb->queries ?? []));
        $work();

        return \count((array) ($wpdb->queries ?? [])) - $before;
    }
}
