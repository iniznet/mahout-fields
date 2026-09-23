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
use Iniznet\Mahout\Fields\Contracts\ControlRegistry;
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
 *
 * The package's implementation of `Contracts\ControlRegistry`; a type is
 * named by its enum case or by that case's value because the filtered map is
 * keyed by the string and a declaration carries the enum. Both forms answer to
 * one control, looked up in one place.
 */
final class FieldTypeRegistry implements ControlRegistry
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
    #[\Override]
    public function control(FieldType|string $type): FieldControl
    {
        $key = $this->key($type);

        return $this->controls[$key]
            ?? throw InvalidFieldDefinition::noControl($key);
    }

    #[\Override]
    public function has(FieldType|string $type): bool
    {
        return isset($this->controls[$this->key($type)]);
    }

    private function key(FieldType|string $type): string
    {
        return $type instanceof FieldType ? $type->value : $type;
    }
}
