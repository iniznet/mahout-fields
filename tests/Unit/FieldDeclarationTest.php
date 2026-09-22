<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\BooleanField;
use Iniznet\Mahout\Fields\ChoiceField;
use Iniznet\Mahout\Fields\DateField;
use Iniznet\Mahout\Fields\DecimalField;
use Iniznet\Mahout\Fields\EmailField;
use Iniznet\Mahout\Fields\Exception\DuplicateFieldId;
use Iniznet\Mahout\Fields\Exception\GroupAlreadyRegistered;
use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidFieldId;
use Iniznet\Mahout\Fields\Exception\InvalidStorageCombination;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\IntegerField;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;
use Iniznet\Mahout\Fields\UrlField;

/**
 * Deliverable 1's negatives, plus the declaration invariants.
 *
 * storage is a required constructor parameter with no default: a declaration
 * without one is an ArgumentError at the declaration site, before anything can
 * be registered, stored or rendered. The two invalid storage combinations are
 * refused at registration, against the RESOLVED target.
 *
 * @internal
 */
final class FieldDeclarationTest extends TestCase
{
    public function testAFieldDeclarationWithoutStorageIsUnrepresentable(): void
    {
        $this->expectException(\ArgumentCountError::class);

        // The analyzer itself refuses this call at max level; the runtime
        // proof is that PHP refuses it too. There is no default to fall to.
        new TextField('fixture_text');
    }

    public function testStorageHasNoDefaultOnAnyFieldConstructor(): void
    {
        $classes = [
            TextField::class,
            \Iniznet\Mahout\Fields\TextAreaField::class,
            EmailField::class,
            UrlField::class,
            IntegerField::class,
            DecimalField::class,
            BooleanField::class,
            DateField::class,
            ChoiceField::class,
        ];

        foreach ($classes as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();

            self::assertNotNull($constructor, $class);
            $parameters = $constructor->getParameters();
            self::assertSame('id', $parameters[0]->getName(), $class);
            self::assertSame('storage', $parameters[1]->getName(), $class);
            self::assertFalse($parameters[1]->isDefaultValueAvailable(), $class.' must not default storage');
        }
    }

    public function testAnOptionContextTableFieldIsRefusedAtRegistration(): void
    {
        $this->expectException(InvalidStorageCombination::class);

        $this->registry->register(new FieldGroup('fixture_options', ObjectContext::Option, [
            new TextField('fixture_option_text', StorageTarget::Table),
        ]));
    }

    public function testTheRefusalNamesTheFieldAndBothSides(): void
    {
        try {
            $this->registry->register(new FieldGroup('fixture_options', ObjectContext::Option, [
                new TextField('fixture_option_table', StorageTarget::Table),
            ]));
            self::fail('An option-context Table field must be refused.');
        } catch (InvalidStorageCombination $refusal) {
            self::assertSame('fixture_option_table', $refusal->fieldId());
            self::assertSame('option', $refusal->context());
            self::assertSame('table', $refusal->storage());
        }
    }

    public function testAnOptionContextMetaFieldRegisters(): void
    {
        $this->registry->register(new FieldGroup('fixture_options', ObjectContext::Option, [
            new TextField('fixture_option_note', StorageTarget::Meta),
        ]));

        self::assertTrue($this->registry->has('fixture_option_note'));
        self::assertSame(ObjectContext::Option, $this->registry->resolve('fixture_option_note')->group->context);
    }

    public function testADuplicateFieldIdInsideOneGroupIsRefused(): void
    {
        $this->expectException(DuplicateFieldId::class);

        $this->registry->register(new FieldGroup('fixture_dups', ObjectContext::Post, [
            new TextField('fixture_dup', StorageTarget::Meta),
            new IntegerField('fixture_dup', StorageTarget::Table),
        ]));
    }

    public function testADuplicateFieldIdAcrossGroupsIsRefused(): void
    {
        $this->registry->register(new FieldGroup('fixture_first', ObjectContext::Post, [
            new TextField('fixture_shared', StorageTarget::Meta),
        ]));

        $this->expectException(DuplicateFieldId::class);
        $this->registry->register(new FieldGroup('fixture_second', ObjectContext::Post, [
            new TextField('fixture_shared', StorageTarget::Table),
        ]));
    }

    public function testASecondRegistrationOfOneGroupIdIsRefused(): void
    {
        $this->registry->register(new FieldGroup('fixture_group', ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Meta),
        ]));

        $this->expectException(GroupAlreadyRegistered::class);
        $this->registry->register(new FieldGroup('fixture_group', ObjectContext::Post, [
            new TextField('fixture_other', StorageTarget::Table),
        ]));
    }

    public function testAnEmptyGroupIsRefusedAtRegistration(): void
    {
        $this->expectException(InvalidFieldId::class);
        $this->registry->register(new FieldGroup('fixture_empty', ObjectContext::Post, []));
    }

    public function testAFieldIdOverTheCapIsRefusedAtDeclaration(): void
    {
        $this->expectException(InvalidFieldId::class);
        new TextField(str_repeat('a', 65), StorageTarget::Meta);
    }

    public function testAMixedCaseFieldIdIsRefusedAtDeclaration(): void
    {
        $this->expectException(InvalidFieldId::class);
        new TextField('Fixture_Text', StorageTarget::Meta);
    }

    public function testAChoiceFieldWithoutOptionsIsRefusedAtDeclaration(): void
    {
        $this->expectException(InvalidFieldDefinition::class);
        new ChoiceField('fixture_choice', StorageTarget::Meta, []);
    }

    public function testARepeaterOfRepeatersIsRefusedAtDeclaration(): void
    {
        $this->expectException(InvalidFieldDefinition::class);
        new RepeaterField(
            'fixture_nested',
            StorageTarget::Meta,
            new RepeaterField('fixture_inner', StorageTarget::Meta, new TextField('fixture_item', StorageTarget::Meta)),
        );
    }

    public function testANonPositiveItemExpectationIsRefusedAtDeclaration(): void
    {
        $this->expectException(InvalidFieldDefinition::class);
        new RepeaterField('fixture_bad_cap', StorageTarget::Table, new TextField('fixture_item', StorageTarget::Table), expectedMaxItems: 0);
    }

    public function testTheStorageTargetFilterResolutionIsAuthoritative(): void
    {
        // The declaration says Table; the filter says Meta; the combination
        // rules run against the RESOLVED target, so a flip to Meta is what
        // makes an option-context field legal. This filter is the migration
        // lever, and its resolution -- never the declaration -- is dispatched on.
        \add_filter(\Iniznet\Mahout\Fields\Hooks::STORAGE_TARGET, static fn (): StorageTarget => StorageTarget::Meta);

        try {
            $this->registry->register(new FieldGroup('fixture_flip', ObjectContext::Option, [
                new TextField('fixture_flipped', StorageTarget::Table),
            ]));

            self::assertTrue($this->registry->has('fixture_flipped'));
            self::assertSame(StorageTarget::Meta, $this->registry->resolve('fixture_flipped')->storage);
        } finally {
            \remove_all_filters(\Iniznet\Mahout\Fields\Hooks::STORAGE_TARGET);
        }
    }

    public function testAFilterReturningAnythingButAStorageTargetIsRefused(): void
    {
        \add_filter(\Iniznet\Mahout\Fields\Hooks::STORAGE_TARGET, static fn (): string => 'meta');

        try {
            $this->expectException(\Iniznet\Mahout\Fields\Exception\InvalidFilterResult::class);
            $this->registry->register(new FieldGroup('fixture_filtered', ObjectContext::Post, [
                new TextField('fixture_filtered_field', StorageTarget::Meta),
            ]));
        } finally {
            \remove_all_filters(\Iniznet\Mahout\Fields\Hooks::STORAGE_TARGET);
        }
    }

    public function testAGroupIdOverTheCapIsRefusedAtDeclaration(): void
    {
        $this->expectException(InvalidFieldId::class);
        new FieldGroup(str_repeat('a', 65), ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Meta),
        ]);
    }
}
