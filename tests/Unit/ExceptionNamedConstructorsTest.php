<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Exception\ConcurrentEditLost;
use Iniznet\Mahout\Fields\Exception\DuplicateFieldId;
use Iniznet\Mahout\Fields\Exception\FieldNotFound;
use Iniznet\Mahout\Fields\Exception\GroupAlreadyRegistered;
use Iniznet\Mahout\Fields\Exception\GroupNotFound;
use Iniznet\Mahout\Fields\Exception\InvalidControlOverride;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidFieldId;
use Iniznet\Mahout\Fields\Exception\InvalidFieldValue;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\Exception\InvalidFilterResult;
use Iniznet\Mahout\Fields\Exception\InvalidMirrorPayload;
use Iniznet\Mahout\Fields\Exception\InvalidPanelDeclaration;
use Iniznet\Mahout\Fields\Exception\InvalidRepeaterPayload;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\Exception\MahoutException;
use Iniznet\Mahout\Fields\Exception\RepeaterTooLarge;
use Iniznet\Mahout\Fields\Exception\UnresolvableFieldStyles;
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
        ConcurrentEditLost::class,
        GroupNotFound::class,
        InvalidMirrorPayload::class,
        InvalidPanelDeclaration::class,
        InvalidControlOverride::class,
        UnresolvableFieldStyles::class,
    ];

    public function testEveryPackageExceptionImplementsTheMarker(): void
    {
        foreach (self::ALL as $exception) {
            self::assertContains(MahoutException::class, class_implements($exception) ?: [], $exception);
        }
    }

    public function testConcurrentEditLostNamesTheGroupAndTheObject(): void
    {
        $refusal = ConcurrentEditLost::forGroup('fixture_group', 42);

        self::assertSame('fixture_group', $refusal->groupId());
        self::assertSame(42, $refusal->objectId());
        self::assertStringContainsString('fixture_group', $refusal->getMessage());
    }

    public function testGroupNotFoundNamesTheGroup(): void
    {
        $refusal = GroupNotFound::forId('fixture_group');

        self::assertSame('fixture_group', $refusal->groupId());
        self::assertStringContainsString('fixture_group', $refusal->getMessage());
    }

    public function testInvalidMirrorPayloadCarriesTheGroupAndItsReason(): void
    {
        $malformed = InvalidMirrorPayload::malformed('fixture_group', 'Syntax error');
        $unknown = InvalidMirrorPayload::unknownField('fixture_group', 'fixture_text');

        self::assertSame('fixture_group', $malformed->groupId());
        self::assertSame('malformed', $malformed->reason());
        self::assertSame('fixture_group', $unknown->groupId());
        self::assertSame('unknown_field', $unknown->reason());
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
        self::assertSame('item', InvalidFieldDefinition::emptyRepeaterItem('r')->reason());
        self::assertSame('member', InvalidFieldDefinition::memberNotCarried('r', 'm')->reason());
        self::assertSame('member', InvalidFieldDefinition::duplicateMemberId('r', 'm')->reason());
        self::assertSame('depth', InvalidFieldDefinition::nestingTooDeep('r', 'm')->reason());
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
        self::assertSame('shape', InvalidFieldWrite::badRepeaterAddress('r')->reason());
        self::assertSame('shape', InvalidFieldWrite::addressTooLong('r')->reason());
        self::assertSame('context', InvalidFieldWrite::optionRow('o')->reason());
        self::assertSame('shape', InvalidFieldWrite::unreadableMeta('m')->reason());
        self::assertSame('backend', InvalidFieldWrite::metaRefused('m')->reason());
    }

    public function testInvalidStorageCombinationCarriesBothSides(): void
    {
        $optionTable = InvalidStorageCombination::optionContextTable('o');

        self::assertSame('option', $optionTable->context());
        self::assertSame('table', $optionTable->storage());
        self::assertStringContainsString('repeater', InvalidStorageCombination::carriedOutsideRepeater('i')->getMessage());
        self::assertSame('carried', InvalidStorageCombination::queriedCarriedRepeater('i')->storage());
    }

    public function testRepeaterTooLargeCarriesSizeAndCap(): void
    {
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

    public function testInvalidPanelDeclarationNamesTheGroupItRefuses(): void
    {
        $refusal = InvalidPanelDeclaration::emptyPostType('fixture_group');

        self::assertSame('fixture_group', $refusal->groupId());
        self::assertStringContainsString('fixture_group', $refusal->getMessage());
    }

    public function testInvalidPanelDeclarationNamesTheOptionScreenRefusals(): void
    {
        self::assertSame('fixture_group', InvalidPanelDeclaration::emptyPageSlug('fixture_group')->groupId());
        self::assertSame('fixture_group', InvalidPanelDeclaration::emptyPageTitle('fixture_group')->groupId());
        self::assertSame('fixture_group', InvalidPanelDeclaration::emptyMenuTitle('fixture_group')->groupId());
        self::assertSame('fixture_group', InvalidPanelDeclaration::emptyCapability('fixture_group')->groupId());
        self::assertSame('fixture_group', InvalidPanelDeclaration::emptyMenuParent('fixture_group')->groupId());

        $context = InvalidPanelDeclaration::notOptionContext('fixture_group', 'post');

        self::assertSame('fixture_group', $context->groupId());
        self::assertStringContainsString('post', $context->getMessage());
    }

    public function testInvalidFieldContextRefusesMismatchedPanelProps(): void
    {
        $option = InvalidFieldContext::panelObject('fixture_group', 'option', 3);

        self::assertSame('option', $option->declared());
        self::assertSame('3', $option->given());

        $row = InvalidFieldContext::panelObject('fixture_group', 'post', 0);

        self::assertSame('post', $row->declared());
        self::assertSame('0', $row->given());
    }

    public function testInvalidControlOverrideNamesTheFieldAndTheClass(): void
    {
        $refusal = InvalidControlOverride::notAControl('fixture_text', \stdClass::class);

        self::assertStringContainsString('fixture_text', $refusal->getMessage());
        self::assertStringContainsString('stdClass', $refusal->getMessage());
    }

    public function testUnresolvableFieldStylesCarriesThePath(): void
    {
        $refusal = UnresolvableFieldStyles::outsideContent('/somewhere/fields.css');

        self::assertStringContainsString('/somewhere/fields.css', $refusal->getMessage());
    }
}
