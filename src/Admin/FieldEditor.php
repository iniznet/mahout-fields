<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\ChoiceField;
use Iniznet\Mahout\Fields\Contracts\ControlRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldEditor as FieldEditorContract;
use Iniznet\Mahout\Fields\Contracts\FieldReader;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Exception\InvalidFieldWrite;
use Iniznet\Mahout\Fields\FieldGroup;
use Iniznet\Mahout\Fields\ObjectContext;
use Iniznet\Mahout\Fields\ObjectKind;
use Iniznet\Mahout\Fields\ObjectRef;
use Iniznet\Mahout\Fields\RepeaterField;

/**
 * The field panel's renderer. props() projects a registered group onto typed
 * control props -- every value read through the field layer, never a raw meta
 * call -- and render() hands the controls to their markup files. propsForGroup()
 * is the option context's variant: a group whose context is Option addresses
 * no object at all, so its props carry no kind and no object id, and a caller
 * cannot construct the mismatch by passing one.
 *
 * The nonce field markup arrives built by the caller (the metabox callback,
 * the option screen's render callback), so this class performs no request-side
 * work and no panel ever reads a superglobal.
 *
 * The control map is reached through `Contracts\ControlRegistry`, never the
 * concrete registry: the renderer depends on the lookup, and the host that
 * replaces a control replaces it through the editor_controls filter.
 */
final readonly class FieldEditor implements FieldEditorContract
{
    public function __construct(
        private ControlRegistry $controls,
        private FieldRegistry $registry,
        private FieldReader $reader,
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
            throw InvalidFieldContext::mismatch($groupId, $group->context->value, $object->context->value);
        }

        return $this->propsFor($group, $object, $nonceField, $errors);
    }

    #[\Override]
    public function propsForGroup(string $groupId, string $nonceField = '', array $errors = []): FieldEditorProps
    {
        $group = $this->registry->group($groupId);

        if (ObjectContext::Option !== $group->context) {
            throw InvalidFieldContext::mismatch($groupId, $group->context->value, ObjectContext::Option->value);
        }

        return $this->propsFor($group, ObjectRef::option(), $nonceField, $errors);
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

    /**
     * The projection both entries share: every value read through the field
     * layer, the expected-state hash read back from the group's reference, and
     * the object kind taken from the object -- null for the option context,
     * whose props carry no kind and no object id at all.
     *
     * @param array<string, string> $errors field id to message
     */
    private function propsFor(FieldGroup $group, ObjectRef $object, string $nonceField, array $errors): FieldEditorProps
    {
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
                    inputName: Nonces::valueField().'['.$group->id.']['.$field->id.'][]',
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
                inputName: Nonces::valueField().'['.$group->id.']['.$field->id.']',
                inputId: 'mahout-field-'.$field->id,
                value: $this->reader->value($field->id, $object),
                emptyLabel: $field->emptyLabel(),
                error: $errors[$field->id] ?? null,
                options: $field instanceof ChoiceField ? $field->options : [],
            );
        }

        return new FieldEditorProps(
            groupId: $group->id,
            objectKind: $object->objectKind(),
            objectId: $object->id,
            controls: $controlProps,
            nonceField: $nonceField,
            expectedHash: $this->reader->hash($group->id, $object),
            errors: $errors,
        );
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
