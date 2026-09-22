<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\ChoiceField;
use Iniznet\Mahout\Fields\Contracts\FieldEditor as FieldEditorContract;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;

/**
 * The field panel's renderer. props() projects a registered group onto typed
 * control props -- every value read through the field layer, never a raw meta
 * call -- and render() hands the controls to their markup files.
 *
 * The nonce field markup arrives built by the caller (the metabox callback),
 * so this class performs no request-side work and no panel ever reads a
 * superglobal.
 */
final readonly class FieldEditor implements FieldEditorContract
{
    public function __construct(
        private FieldTypeRegistry $controls,
        private \Iniznet\Mahout\Fields\Contracts\FieldRegistry $registry,
        private \Iniznet\Mahout\Fields\Contracts\FieldReader $reader,
    ) {
    }

    #[\Override]
    public function props(
        string $groupId,
        int $objectId,
        ObjectKind $objectKind,
        string $nonceField = '',
        array $errors = [],
    ): FieldEditorProps {
        $object = self::objectOf($objectKind, $objectId);
        $group = $this->registry->group($groupId);

        if ($group->context !== $object->context) {
            throw \Iniznet\Mahout\Fields\Exception\InvalidFieldContext::mismatch($groupId, $group->context->value, $object->context->value);
        }

        $controlProps = [];

        foreach ($group->fields as $field) {
            if ($field instanceof RepeaterField) {
                $items = $this->reader->items($field->id, $object);

                foreach ($items as $item) {
                    if (!\is_scalar($item)) {
                        throw InvalidFieldWrite::recordedItems($field->id);
                    }
                }

                $controlProps[] = new FieldControlProps(
                    fieldId: $field->id,
                    type: $field->type(),
                    label: $field->label ?? $field->id,
                    inputName: Nonces::valueField().'['.$groupId.']['.$field->id.'][]',
                    inputId: 'mahout-field-'.$field->id,
                    emptyLabel: $field->emptyLabel(),
                    error: $errors[$field->id] ?? null,
                    items: $items,
                );

                continue;
            }

            $controlProps[] = new FieldControlProps(
                fieldId: $field->id,
                type: $field->type(),
                label: $field->label ?? $field->id,
                inputName: Nonces::valueField().'['.$groupId.']['.$field->id.']',
                inputId: 'mahout-field-'.$field->id,
                value: $this->reader->value($field->id, $object),
                emptyLabel: $field->emptyLabel(),
                error: $errors[$field->id] ?? null,
                options: $field instanceof ChoiceField ? $field->options : [],
            );
        }

        return new FieldEditorProps(
            groupId: $groupId,
            objectKind: $objectKind,
            objectId: $objectId,
            controls: $controlProps,
            nonceField: $nonceField,
            expectedHash: $this->reader->hash($groupId, $object),
            errors: $errors,
        );
    }

    #[\Override]
    public function render(FieldEditorProps $props): string
    {
        $rendered = [];

        foreach ($props->controls as $control) {
            $rendered[] = $this->controls->control($control->type)->render($control);
        }

        \ob_start();
        $panel = ['props' => $props, 'controls' => $rendered];
        require __DIR__.'/Control/markup/panel.php';

        return (string) \ob_get_clean();
    }

    private static function objectOf(ObjectKind $objectKind, int $objectId): ObjectRef
    {
        return match ($objectKind) {
            ObjectKind::Post => ObjectRef::post($objectId),
            ObjectKind::User => ObjectRef::user($objectId),
            ObjectKind::Term => ObjectRef::term($objectId),
        };
    }
}
