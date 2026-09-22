<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Exception\DuplicateFieldId;
use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\GroupAlreadyRegistered;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidFieldId;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\InvalidFilterResult;
use Iniznet\Mahout\Fields\Exception\InvalidRepeaterPayload;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\Exception\MahoutException;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;
use Iniznet\Mahout\Fields\RepeaterCodec;
use Iniznet\Mahout\Fields\Tests\TestCase;

/**
 * Every exception's named constructor, and the package marker every one of
 * them carries, so a consumer can catch the family and nothing else.
 *
 * @internal
 */
final class ExceptionNamedConstructorsTest extends TestCase
{
    /** @return list<class-string> */
    private const ALL = [
        FieldNotFound::class,
        DuplicateFieldId::class,
        GroupAlreadyRegistered::class,
        InvalidFieldId::class,
        InvalidFieldDefinition::class,
        InvalidFieldValue::class,
        InvalidFieldWrite::class,
        InvalidFieldContext::class,
        InvalidStorageCombination::class,
        InvalidFilterResult::class,
        InvalidRepeaterPayload::class,
        RepeaterTooLarge::class,
    ];

    public function testEveryPackageExceptionImplementsTheMarker(): void
    {
        foreach (self::ALL as $exception) {
            self::assertContains(MahoutException::class, class_implements($exception) ?: [], $exception);
        }
    }

    public function testFieldNotFoundNamesTheField(): void
    {
        $refusal = FieldNotFound::forId('isbn');

        self::assertSame('isbn', $refusal->fieldId());
        self::assertStringContainsString('isbn', $refusal->getMessage());
    }

    public function testDuplicateFieldIdCarriesFieldAndGroup(): void
    {
        self::assertSame('f', DuplicateFieldId::inGroup('f', 'g')->fieldId());
        self::assertSame('g2', DuplicateFieldId::acrossGroups('s', 'g2')->groupId());
    }

    public function testGroupAlreadyRegisteredCarriesTheGroupId(): void
    {
        self::assertSame('group', GroupAlreadyRegistered::forId('group')->groupId());
    }

    public function testInvalidFieldIdCarriesKindAndId(): void
    {
        self::assertSame('field', InvalidFieldId::malformed('field', 'Bad')->kind());
        self::assertSame('Bad', InvalidFieldId::malformed('field', 'Bad')->id());
        self::assertSame('field', InvalidFieldId::tooLong('field', str_repeat('a', 65), 64)->kind());
        self::assertSame('group', InvalidFieldId::emptyGroup('group')->kind());
    }

    public function testInvalidFieldDefinitionCarriesFieldAndReason(): void
    {
        self::assertSame('choices', InvalidFieldDefinition::emptyChoiceSet('c')->reason());
        self::assertSame('item', InvalidFieldDefinition::repeaterOfRepeater('r')->reason());
        self::assertSame('items', InvalidFieldDefinition::itemExpectation('e', 0)->reason());
    }

    public function testInvalidFieldValueNamesTheType(): void
    {
        self::assertSame('integer', InvalidFieldValue::notNumeric('n', 'integer', 'x')->type());
        self::assertSame('date', InvalidFieldValue::unparseableDate('d', 'x')->type());
        self::assertSame('choice', InvalidFieldValue::outsideChoices('c', 'nope')->type());
        self::assertSame('email', InvalidFieldValue::emptyEmail('e', 'bad')->type());
        self::assertSame('url', InvalidFieldValue::refused('u', 'url', 'x', 'not a URL')->type());
    }

    public function testInvalidFieldWriteNamesItsCondition(): void
    {
        self::assertSame('shape', InvalidFieldWrite::scalarIntoRepeater('r')->reason());
        self::assertSame('shape', InvalidFieldWrite::itemsIntoScalar('i')->reason());
        self::assertSame('target', InvalidFieldWrite::jsonIntoItemsTable('j')->reason());
        self::assertSame('context', InvalidFieldWrite::optionRow('o')->reason());
        self::assertSame('shape', InvalidFieldWrite::unreadableMeta('m')->reason());
        self::assertSame('backend', InvalidFieldWrite::metaRefused('m')->reason());
    }

    public function testInvalidStorageCombinationCarriesBothSides(): void
    {
        $optionTable = InvalidStorageCombination::optionContextTable('o');

        self::assertSame('option', $optionTable->context());
        self::assertSame('table', $optionTable->storage());
        self::assertStringContainsString('decimal', InvalidStorageCombination::repeaterItemWithoutColumn('i', 'decimal')->getMessage());
    }

    public function testRepeaterTooLargeCarriesSizeAndCap(): void
    {
        $payload = RepeaterTooLarge::payload(70000, RepeaterCodec::MAX_BYTES);

        self::assertSame(70000, $payload->size());
        self::assertSame(RepeaterCodec::MAX_BYTES, $payload->cap());

        $items = RepeaterTooLarge::items(5, 2);

        self::assertSame(5, $items->size());
        self::assertSame(2, $items->cap());
    }

    public function testInvalidFieldContextCarriesDeclaredAndGiven(): void
    {
        $mismatch = InvalidFieldContext::mismatch('f', 'post', 'user');

        self::assertSame('post', $mismatch->declared());
        self::assertSame('user', $mismatch->given());
    }

    public function testInvalidFilterResultCarriesTheHook(): void
    {
        self::assertSame('hook', InvalidFilterResult::notAStorageTarget('hook')->hook());
        self::assertSame('hook2', InvalidFilterResult::notASanitisedScalar('hook2')->hook());
    }

    public function testInvalidRepeaterPayloadCarriesItsReason(): void
    {
        self::assertSame('shape', InvalidRepeaterPayload::notAnEnvelope()->reason());
        self::assertSame('version', InvalidRepeaterPayload::unsupportedVersion(9)->reason());
        self::assertSame('malformed', InvalidRepeaterPayload::malformed('bad json')->reason());
        self::assertSame('item', InvalidRepeaterPayload::malformedItem(3)->reason());
    }
}
