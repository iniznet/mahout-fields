<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Contract;

use Iniznet\Mahout\Fields\BooleanField;
use Iniznet\Mahout\Fields\ChoiceField;
use Iniznet\Mahout\Fields\DateField;
use Iniznet\Mahout\Fields\DecimalField;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * The storage contract: whatever the target, a write followed by a read
 * returns the declared value, absence reads as null, and a delete removes it.
 *
 * The same matrix runs against both adapters, which is the whole point of the
 * encapsulation rule: the two targets are interchangeable behind one reader.
 *
 * @internal
 */
final class FieldStorageContractTest extends TestCase
{
    /**
     * @return array<string, array{0: StorageTarget}>
     */
    public static function targets(): array
    {
        return [
            'meta' => [StorageTarget::Meta],
            'table' => [StorageTarget::Table],
        ];
    }

    /**
     * @dataProvider targets
     */
    public function testEveryScalarTypeRoundTrips(StorageTarget $target): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group($target));

        foreach ($this->scalars() as $fieldId => [$write, $expected]) {
            $this->writer->set($fieldId, ObjectRef::post($postId), $write);
            self::assertSame($expected, $this->reader->value($fieldId, ObjectRef::post($postId)), $fieldId.' on '.$target->value);
        }
    }

    /**
     * @dataProvider targets
     */
    public function testAbsenceReadsNull(StorageTarget $target): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group($target));

        self::assertNull($this->reader->value('ct_text', ObjectRef::post($postId)));
    }

    /**
     * @dataProvider targets
     */
    public function testWritingNullRemovesTheValue(StorageTarget $target): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group($target));

        $this->writer->set('ct_text', ObjectRef::post($postId), 'temporary');
        $this->writer->set('ct_text', ObjectRef::post($postId), null);

        self::assertNull($this->reader->value('ct_text', ObjectRef::post($postId)));
    }

    /**
     * @dataProvider targets
     */
    public function testDeleteRemovesTheValue(StorageTarget $target): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group($target));

        $this->writer->set('ct_text', ObjectRef::post($postId), 'to be removed');
        $this->writer->delete('ct_text', ObjectRef::post($postId));

        self::assertNull($this->reader->value('ct_text', ObjectRef::post($postId)));
    }

    /**
     * @dataProvider targets
     */
    public function testAChoiceOutsideTheDeclaredSetIsRefused(StorageTarget $target): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group($target));

        $this->expectException(\Iniznet\Mahout\Fields\Exception\InvalidFieldValue::class);
        $this->writer->set('ct_choice', ObjectRef::post($postId), 'audio');
    }

    /**
     * @dataProvider targets
     */
    public function testAFractionalIntegerIsRefused(StorageTarget $target): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group($target));

        $this->expectException(\Iniznet\Mahout\Fields\Exception\InvalidFieldValue::class);
        $this->writer->set('ct_integer', ObjectRef::post($postId), '3.7');
    }

    private function group(StorageTarget $target): FieldGroup
    {
        return new FieldGroup('ct_group', ObjectContext::Post, [
            new TextField('ct_text', $target),
            new ChoiceField('ct_choice', $target, ['paperback', 'hardcover']),
            new IntegerField('ct_integer', $target),
            new DecimalField('ct_decimal', $target),
            new BooleanField('ct_boolean', $target),
            new DateField('ct_date', $target),
        ]);
    }

    /**
     * @return array<string, array{0: string|int|float|bool, 1: string|int|float|bool}>
     */
    private function scalars(): array
    {
        return [
            'ct_text' => ['contract value', 'contract value'],
            'ct_choice' => ['paperback', 'paperback'],
            'ct_integer' => [7, 7],
            'ct_decimal' => ['2.25', 2.25],
            'ct_boolean' => [true, true],
            'ct_date' => ['2024-11-30', '2024-11-30'],
        ];
    }
}
