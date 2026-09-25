<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Tests\Unit;

use Iniznet\Mahout\Fields\Admin\Control\BooleanControl;
use Iniznet\Mahout\Fields\Admin\Control\ChoiceControl;
use Iniznet\Mahout\Fields\Admin\Control\DateControl;
use Iniznet\Mahout\Fields\Admin\Control\DecimalControl;
use Iniznet\Mahout\Fields\Admin\Control\EmailControl;
use Iniznet\Mahout\Fields\Admin\Control\IntegerControl;
use Iniznet\Mahout\Fields\Admin\Control\RepeaterControl;
use Iniznet\Mahout\Fields\Admin\Control\TextAreaControl;
use Iniznet\Mahout\Fields\Admin\Control\TextControl;
use Iniznet\Mahout\Fields\Admin\Control\UrlControl;
use Iniznet\Mahout\Fields\Admin\FieldControlProps;
use Iniznet\Mahout\Fields\Admin\MemberControl;
use Iniznet\Mahout\Fields\FieldType;
use Iniznet\Mahout\Fields\Tests\TestCase;

/**
 * Every control renders its own markup file with one bound variable and
 * exactly one escape per output: a hostile label, a hostile value and a
 * stored value each appear escaped once and raw nowhere.
 *
 * @internal
 */
final class ControlRenderTest extends TestCase
{
    public function testTheTextControlEscapesLabelAndValueOnce(): void
    {
        $markup = (new TextControl())->render($this->props(value: '<b>bold &</b>', label: 'Series <title>'));

        self::assertStringContainsString('&lt;b&gt;bold &amp;&lt;/b&gt;', $markup);
        self::assertStringContainsString('Series &lt;title&gt;', $markup);
        self::assertStringNotContainsString('<b>bold', $markup);
        self::assertStringContainsString('name="mahout_fields_panel[fixture_group][fixture_text]"', $markup);
        self::assertStringContainsString('id="mahout-field-fixture_text"', $markup);
    }

    public function testTheTextControlRendersItsErrorState(): void
    {
        $markup = (new TextControl())->render($this->props(error: 'Not a valid value.'));

        self::assertStringContainsString('aria-invalid="true"', $markup);
        self::assertStringContainsString('aria-describedby="mahout-field-fixture_text-error"', $markup);
        self::assertStringContainsString('Not a valid value.', $markup);
        self::assertStringNotContainsString('mahout-fields-help', $markup, 'an error and an empty-state hint never render together');
    }

    public function testTheTextControlRendersItsEmptyState(): void
    {
        $markup = (new TextControl())->render($this->props(value: null, emptyLabel: 'No value set.'));

        self::assertStringContainsString('No value set.', $markup);
        self::assertStringNotContainsString('aria-invalid', $markup);
    }

    public function testTheTextAreaControlEscapesItsContentForTextarea(): void
    {
        $markup = (new TextAreaControl())->render($this->props(value: 'line</textarea><script>alert(1)</script>'));

        self::assertStringNotContainsString('</textarea><script>', $markup);
        self::assertStringContainsString('line&lt;/textarea&gt;', $markup);
    }

    public function testTheChoiceControlRendersItsClosedSetWithTheStoredSelection(): void
    {
        $markup = (new ChoiceControl())->render($this->props(value: 'planned', options: ['planned', 'ongoing', 'done']));

        self::assertSame(3, substr_count($markup, '<option'), 'an option outside the declared set cannot exist in the markup');
        self::assertSame(1, substr_count($markup, 'selected="selected"'));
        self::assertStringContainsString('value="planned" selected="selected"', $markup);
    }

    public function testTheChoiceControlCarriesAnEmptyOptionWhenNothingIsStored(): void
    {
        $markup = (new ChoiceControl())->render($this->props(value: null, options: ['planned', 'done'], emptyLabel: 'Nothing selected.'));

        self::assertSame(3, substr_count($markup, '<option'), 'the empty option exists only while the value is null');
        self::assertStringContainsString('Nothing selected.', $markup);
    }

    public function testTheBooleanControlChecksOnlyAStoredTrue(): void
    {
        self::assertSame(1, substr_count((new BooleanControl())->render($this->props(value: true)), 'checked="checked"'));
        self::assertSame(0, substr_count((new BooleanControl())->render($this->props(value: false)), 'checked="checked"'));
    }

    public function testTheIntegerAndDecimalControlsDeclareTheirStep(): void
    {
        self::assertStringContainsString('step="1"', (new IntegerControl())->render($this->props()));
        self::assertStringContainsString('step="0.000001"', (new DecimalControl())->render($this->props()));
    }

    public function testTheEmailUrlAndDateControlsRenderTheirInputTypes(): void
    {
        self::assertStringContainsString('type="email"', (new EmailControl())->render($this->props()));
        self::assertStringContainsString('type="url"', (new UrlControl())->render($this->props()));
        self::assertStringContainsString('type="date"', (new DateControl())->render($this->props()));
    }

    public function testTheRepeaterControlRendersOneInputPerStoredItem(): void
    {
        $member = fn (string $value): MemberControl => new MemberControl(
            new TextControl(),
            new FieldControlProps(
                fieldId: 'fixture_item',
                type: FieldType::Text,
                label: 'Item',
                inputName: 'mahout_fields_panel[fixture_group][fixture_text][]',
                inputId: 'mahout-field-fixture_item',
                value: $value,
            ),
        );

        $markup = (new RepeaterControl())->render($this->props(
            repeaterItems: true,
            rows: [[$member('alpha')], [$member('beta')]],
        ));

        self::assertSame(2, substr_count($markup, '<input'), 'one input per stored item, at its position');
        self::assertSame(2, substr_count($markup, '[]'), 'a repeater\'s name carries the list suffix on every input');
        self::assertStringContainsString('value="alpha"', $markup);
        self::assertStringContainsString('value="beta"', $markup);
    }

    public function testARequiredDisabledControlCarriesBothAttributes(): void
    {
        $markup = (new TextControl())->render($this->props(required: true, disabled: true));

        self::assertStringContainsString('required="required"', $markup);
        self::assertStringContainsString('aria-required="true"', $markup);
        self::assertStringContainsString('disabled="disabled"', $markup);
    }

    // ------------------------------------------------------------------

    private function props(
        string|int|float|bool|null $value = null,
        ?string $label = null,
        ?string $error = null,
        ?string $emptyLabel = null,
        array $options = [],
        array $items = [],
        bool $required = false,
        bool $disabled = false,
        bool $repeaterItems = false,
        array $rows = [],
    ): FieldControlProps {
        return new FieldControlProps(
            fieldId: 'fixture_text',
            type: FieldType::Text,
            label: $label ?? 'Fixture label',
            inputName: 'mahout_fields_panel[fixture_group][fixture_text]'.($repeaterItems ? '[]' : ''),
            inputId: 'mahout-field-fixture_text',
            value: $value,
            emptyLabel: $emptyLabel,
            error: $error,
            required: $required,
            disabled: $disabled,
            options: $options,
            items: $items,
            rows: $rows,
        );
    }
}
