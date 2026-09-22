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
use Iniznet\Mahout\Fields\FieldType;
use Iniznet\Mahout\Fields\Tests\TestCase;

/**
 * The controls' bytes are a promise: a rendered control is byte-identical
 * across requests, so the gate compares each type's three states against a
 * golden file. Set MAHOUT_GOLDEN_REGENERATE=1 to rewrite the goldens; the
 * gate itself never writes.
 *
 * @internal
 */
final class ControlGoldenTest extends TestCase
{
    public function testEveryControlRendersItsGoldenStates(): void
    {
        $types = [
            'text' => [new TextControl(), FieldType::Text],
            'textarea' => [new TextAreaControl(), FieldType::TextArea],
            'email' => [new EmailControl(), FieldType::Email],
            'url' => [new UrlControl(), FieldType::Url],
            'choice' => [new ChoiceControl(), FieldType::Choice],
            'integer' => [new IntegerControl(), FieldType::Integer],
            'decimal' => [new DecimalControl(), FieldType::Decimal],
            'boolean' => [new BooleanControl(), FieldType::Boolean],
            'date' => [new DateControl(), FieldType::Date],
            'repeater' => [new RepeaterControl(), FieldType::Repeater],
        ];

        $regenerate = '1' === (string) getenv('MAHOUT_GOLDEN_REGENERATE');
        $dir = dirname(__DIR__).'/golden/controls';

        foreach ($types as $name => [$control, $type]) {
            foreach ($this->states() as $state => $props) {
                $markup = $control->render($this->props($type, $props));
                $file = $dir.'/'.$name.'-'.$state.'.html';

                if ($regenerate) {
                    \file_put_contents($file, $markup);
                    self::assertFileExists($file);
                    continue;
                }

                self::assertFileExists($file, 'the golden for '.$name.'-'.$state.' is a publishing item, not an afterthought');
                self::assertStringEqualsFile($file, $markup, 'the rendered '.$name.' control must be byte-identical to its golden');
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function states(): array
    {
        return [
            'empty' => ['value' => null, 'emptyLabel' => null],
            'populated' => ['value' => 'stored value', 'emptyLabel' => null, 'items' => ['alpha', 'beta']],
            'invalid' => ['value' => null, 'error' => 'That value was refused.', 'emptyLabel' => null],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function props(FieldType $type, array $overrides): FieldControlProps
    {
        $choiceOptions = ['planned', 'ongoing', 'done'];
        $items = \in_array('items', \array_keys($overrides), true) ? $overrides['items'] : [];
        $value = $overrides['value'] ?? null;

        if (FieldType::Choice === $type) {
            $value = \is_string($value ?? null) && \in_array($value, $choiceOptions, true) ? $value : null;
        }

        if (FieldType::Boolean === $type) {
            $value = \is_string($overrides['value'] ?? null) && 'stored value' === $overrides['value'];
        }

        if (FieldType::Date === $type) {
            $value = \is_string($overrides['value'] ?? null) && 'stored value' !== $value ? $value : null;
        }

        return new FieldControlProps(
            fieldId: 'fixture_field',
            type: $type,
            label: 'Fixture label',
            inputName: 'mahout_fields_panel[fixture_group][fixture_field]'.(FieldType::Repeater === $type ? '[]' : ''),
            inputId: 'mahout-field-fixture_field',
            value: $value,
            emptyLabel: $overrides['emptyLabel'] ?? null,
            error: $overrides['error'] ?? null,
            required: true,
            options: $choiceOptions,
            items: \is_array($value ?? null) ? [] : ($overrides['items'] ?? []),
        );
    }
}
