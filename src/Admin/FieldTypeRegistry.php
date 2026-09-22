<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

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
use Iniznet\Mahout\Fields\Contracts\FieldControl;
use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\Exception\InvalidFilterResult;
use Iniznet\Mahout\Fields\FieldType;
use Iniznet\Mahout\Fields\Hooks;

/**
 * The editor registry: field type to control, built once and extended by a
 * host through the mahout/fields/editor_controls filter. A filter result is a
 * trust boundary: a non-array, a non-control value or a dropped built-in type
 * is refused loudly, never coerced and never silently missing.
 */
final class FieldTypeRegistry
{
    /** @var array<string, FieldControl> */
    private array $controls;

    public function __construct()
    {
        $declared = [
            FieldType::Text->value => new TextControl(),
            FieldType::TextArea->value => new TextAreaControl(),
            FieldType::Email->value => new EmailControl(),
            FieldType::Url->value => new UrlControl(),
            FieldType::Choice->value => new ChoiceControl(),
            FieldType::Integer->value => new IntegerControl(),
            FieldType::Decimal->value => new DecimalControl(),
            FieldType::Boolean->value => new BooleanControl(),
            FieldType::Date->value => new DateControl(),
            FieldType::Repeater->value => new RepeaterControl(),
        ];

        $filtered = \apply_filters(Hooks::EDITOR_CONTROLS, $declared);

        if (!\is_array($filtered)) {
            throw InvalidFilterResult::notAControlMap(Hooks::EDITOR_CONTROLS);
        }

        $controls = [];

        foreach ($filtered as $type => $control) {
            if (!$control instanceof FieldControl) {
                throw InvalidFilterResult::notAControl(Hooks::EDITOR_CONTROLS);
            }

            $controls[(string) $type] = $control;
        }

        $this->controls = $controls;
    }

    /**
     * @throws InvalidFieldDefinition when no control serves the type
     */
    public function control(FieldType $type): FieldControl
    {
        return $this->controls[$type->value]
            ?? throw InvalidFieldDefinition::noControl($type->value);
    }
}
