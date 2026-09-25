<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Admin\FieldEditor;
use Iniznet\Mahout\Fields\Admin\FieldEditorProps;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;
use Iniznet\Mahout\Fields\StorageTarget;
use Iniznet\Mahout\Fields\Tests\TestCase;
use Iniznet\Mahout\Fields\TextField;

/**
 * The panel renderer: props() projects a registered group onto typed control
 * props, every value read through the field layer; render() hands each
 * control to its markup and wraps them in the panel shell; a group whose
 * context does not match the object's is refused before anything renders.
 *
 * @internal
 */
final class FieldEditorTest extends TestCase
{
    private const string GROUP = 'fixture_group';

    private const string OPTION_GROUP = 'fixture_option_group';

    public function testPropsProjectsTheGroupOntoTypedControlProps(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $object = ObjectRef::post($postId);
        $this->writer->set('fixture_text', $object, 'stored value');
        $this->writer->setItems('fixture_repeater', $object, ['alpha', 'beta']);
        $editor = $this->editor();

        $props = $editor->props(self::GROUP, $postId, ObjectKind::Post, '<nonce-field/>');

        self::assertSame(self::GROUP, $props->groupId);
        self::assertSame(ObjectKind::Post, $props->objectKind);
        self::assertSame($postId, $props->objectId);
        self::assertSame('<nonce-field/>', $props->nonceField, 'the nonce markup arrives built by the caller');
        self::assertSame($this->reader->hash(self::GROUP, $object), $props->expectedHash);
        self::assertCount(2, $props->controls);

        [$text, $repeater] = $props->controls;

        self::assertSame('fixture_text', $text->fieldId);
        self::assertSame('stored value', $text->value, 'the value is read through the field layer, never a raw meta call');
        self::assertSame('mahout_fields_panel[fixture_group][fixture_text]', $text->inputName);
        self::assertSame('alpha', $repeater->items[0] ?? null, 'the repeater\'s items arrive in declared order');
        self::assertSame('mahout_fields_panel[fixture_group][fixture_repeater][]', $repeater->inputName, 'a repeater\'s name carries the list suffix');
    }

    public function testRenderReturnsThePanelWithEveryControl(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $this->writer->set('fixture_text', ObjectRef::post($postId), 'rendered');
        $editor = $this->editor();

        $markup = $editor->render($editor->props(self::GROUP, $postId, ObjectKind::Post, '<nonce/>'));

        self::assertStringContainsString('mahout-fields-panel', $markup);
        self::assertStringContainsString('data-mahout-group="fixture_group"', $markup);
        self::assertStringContainsString('<nonce/>', $markup);
        self::assertSame(1, substr_count($markup, 'id="mahout-field-fixture_text"'), 'each control renders exactly once');
        self::assertSame(1, substr_count($markup, 'mahout-fields-control--repeater'));
    }

    public function testAContextMismatchIsRefusedBeforeAnythingRenders(): void
    {
        $userId = $this->userId();
        $this->registry->register($this->group());

        try {
            $this->editor()->props(self::GROUP, $userId, ObjectKind::User, '');
            self::fail('a post group cannot render for a user object');
        } catch (InvalidFieldContext) {
            self::addToAssertionCount(1);
        }
    }

    public function testPropsForGroupProjectsAnOptionGroupWithoutAnObjectKind(): void
    {
        $this->registry->register($this->optionGroup());
        $this->writer->set('fixture_option_text', ObjectRef::option(), 'stored option');
        $editor = $this->editor();

        $props = $editor->propsForGroup(self::OPTION_GROUP, '<nonce-field/>');

        self::assertSame(self::OPTION_GROUP, $props->groupId);
        self::assertNull($props->objectKind, 'the option context has no kind: an option is a singleton read by key, never a row');
        self::assertSame(0, $props->objectId);
        self::assertSame('<nonce-field/>', $props->nonceField, 'the nonce markup arrives built by the caller, as on every panel');
        self::assertSame($this->reader->hash(self::OPTION_GROUP, ObjectRef::option()), $props->expectedHash);
        self::assertCount(1, $props->controls);
        self::assertSame('stored option', $props->controls[0]->value, 'the value is read through the field layer, never a raw option call');
        self::assertSame('mahout_fields_panel[fixture_option_group][fixture_option_text]', $props->controls[0]->inputName);
    }

    public function testAnOptionPanelRendersWithoutTheObjectAttributes(): void
    {
        $this->registry->register($this->optionGroup());
        $editor = $this->editor();

        $markup = $editor->render($editor->propsForGroup(self::OPTION_GROUP, '<nonce/>'));

        self::assertStringContainsString('data-mahout-group="'.self::OPTION_GROUP.'"', $markup);
        self::assertStringNotContainsString('data-object-kind', $markup, 'the option context has no object kind to name');
        self::assertStringNotContainsString('data-object-id', $markup);
        self::assertStringContainsString('<nonce/>', $markup);
        self::assertStringContainsString('name="mahout_fields_hash['.self::OPTION_GROUP.']"', $markup, 'the hash field rides the option panel like any other');
        self::assertSame(1, substr_count($markup, 'id="mahout-field-fixture_option_text"'), 'each control renders exactly once');
    }

    public function testPropsForGroupRefusesAGroupThatIsNotOptionContext(): void
    {
        $this->registry->register($this->group());

        try {
            $this->editor()->propsForGroup(self::GROUP, '');
            self::fail('a row group cannot render as an option screen');
        } catch (InvalidFieldContext) {
            self::addToAssertionCount(1);
        }
    }

    public function testMismatchedPanelPropsCannotExist(): void
    {
        try {
            new FieldEditorProps(self::GROUP, ObjectKind::Post, 0, [], '', '');
            self::fail('a row panel carries an object id; there is no zero-object post');
        } catch (InvalidFieldContext) {
            self::addToAssertionCount(1);
        }

        try {
            new FieldEditorProps(self::OPTION_GROUP, null, 3, [], '', '');
            self::fail('the option context has no object; the props carry no id');
        } catch (InvalidFieldContext) {
            self::addToAssertionCount(1);
        }
    }

    public function testAnErrorMapReachesItsControl(): void
    {
        $postId = $this->postId();
        $this->registry->register($this->group());
        $editor = $this->editor();

        $markup = $editor->render($editor->props(self::GROUP, $postId, ObjectKind::Post, '', ['fixture_text' => 'That value was refused.']));

        self::assertStringContainsString('That value was refused.', $markup);
        self::assertStringContainsString('aria-invalid="true"', $markup);
        self::assertSame(1, substr_count($markup, 'id="mahout-field-fixture_text"'), 'each control renders exactly once, whatever its error state');
    }

    // ------------------------------------------------------------------

    private function editor(): FieldEditor
    {
        return new FieldEditor(
            new \Iniznet\Mahout\Fields\Admin\FieldTypeRegistry(),
            $this->registry,
            $this->reader,
        );
    }

    private function group(): FieldGroup
    {
        return new FieldGroup(self::GROUP, ObjectContext::Post, [
            new TextField('fixture_text', StorageTarget::Table),
            new RepeaterField('fixture_repeater', StorageTarget::Table, new TextField('fixture_item', StorageTarget::Carried)),
        ]);
    }

    private function optionGroup(): FieldGroup
    {
        return new FieldGroup(self::OPTION_GROUP, ObjectContext::Option, [
            new TextField('fixture_option_text', StorageTarget::Meta),
        ]);
    }
}
