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

use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
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
