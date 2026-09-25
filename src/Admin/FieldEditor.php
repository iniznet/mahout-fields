<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

use Iniznet\Mahout\Fields\ChoiceField;
use Iniznet\Mahout\Fields\Contracts\ControlRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldControl as FieldControlContract;
use Iniznet\Mahout\Fields\Contracts\FieldEditor as FieldEditorContract;
use Iniznet\Mahout\Fields\Contracts\FieldReader;
use Iniznet\Mahout\Fields\Contracts\FieldRegistry;
use Iniznet\Mahout\Fields\Contracts\FieldUiPolicy;
use Iniznet\Mahout\Fields\Exception\InvalidControlOverride;
use Iniznet\Mahout\Fields\Exception\InvalidFieldContext;
use Iniznet\Mahout\Fields\Field;
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
        private ?FieldUiPolicy $ui = null,
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

        $resolved = [];

        foreach ($props->controls as $control) {
            $resolved[$control->fieldId] ??= $this->controlFor($control);

            $rendered[] = $resolved[$control->fieldId]->render($control);
        }

        \ob_start();
        $panel = ['props' => $props, 'controls' => $rendered];
        require __DIR__.'/Control/markup/panel.php';

        return (string) \ob_get_clean();
    }

    /**
     * The control one field renders: the policy's replacement when it names
     * one, the type's built-in otherwise. A named class that is not a control
     * is refused at the render site -- the policy is a trust boundary, and a
     * silent skip would render a field the host believes it replaced.
     */
    private function controlFor(FieldControlProps $control): FieldControlContract
    {
        $override = $this->ui?->fields()[$control->fieldId]->control ?? null;

        if (null === $override) {
            return $this->controls->control($control->type);
        }

        if (!\is_a($override, FieldControlContract::class, true)) {
            throw InvalidControlOverride::notAControl($control->fieldId, $override);
        }

        return new $override();
    }

    /**
     * One row per stored item, each row the member controls that submit it.
     * A scalar item is one control; a composite item is one control per
     * member; a nested repeater member recurses, its input name carrying the
     * position chain the writer's address grammar reads back.
     *
     * @param list<mixed>           $items
     * @param array<string, string> $errors
     *
     * @return list<list<MemberControl>>
     */
    private function repeaterRows(RepeaterField $field, string $nameBase, string $idBase, array $items, array $errors): array
    {
        $rows = [];
        $scalar = $field->item instanceof Field;

        foreach ($items as $position => $item) {
            $row = [];

            if ($scalar) {
                $row[] = $this->memberControl(
                    $field->item,
                    $nameBase.'[]',
                    $idBase.'-'.$position,
                    \is_scalar($item) ? $item : null,
                    $errors,
                );

                $rows[] = $row;

                continue;
            }

            if (!\is_array($item)) {
                continue;
            }

            foreach ($field->members() as $member) {
                $value = $item[$member->id] ?? null;

                if ($member instanceof RepeaterField) {
                    $row[] = $this->repeaterMember(
                        $member,
                        $nameBase.'['.$position.']['.$member->id.']',
                        $idBase.'-'.$position.'-'.$member->id,
                        \is_array($value) ? $value : [],
                        $errors,
                    );

                    continue;
                }

                $row[] = $this->memberControl(
                    $member,
                    $nameBase.'['.$position.']['.$member->id.']',
                    $idBase.'-'.$position.'-'.$member->id,
                    \is_scalar($value) ? $value : null,
                    $errors,
                );
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $items
     */
    private function repeaterMember(RepeaterField $field, string $nameBase, string $idBase, array $items, array $errors): MemberControl
    {
        $scalar = $field->item instanceof Field;

        return new MemberControl(
            new RepeaterControl(),
            new FieldControlProps(
                fieldId: $field->id,
                type: $field->type(),
                label: $field->label ?? $field->id,
                inputName: $nameBase.($scalar ? '[]' : ''),
                inputId: $idBase,
                emptyLabel: $field->emptyLabel(),
                rows: $this->repeaterRows($field, $nameBase, $idBase, $items, $errors),
                layout: $field->layout,
            ),
        );
    }

    private function memberControl(Field $member, string $inputName, string $inputId, string|int|float|bool|null $value, array $errors): MemberControl
    {
        $props = new FieldControlProps(
            fieldId: $member->id,
            type: $member->type(),
            label: $member->label ?? $member->id,
            inputName: $inputName,
            inputId: $inputId,
            value: $value,
            emptyLabel: $member->emptyLabel(),
            error: $errors[$member->id] ?? null,
            options: $member instanceof ChoiceField ? $member->options : [],
        );

        return new MemberControl($this->controls->control($member->type()), $props);
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
            $ui = $this->ui?->fields()[$field->id] ?? null;

            if ($field instanceof RepeaterField) {
                $base = Nonces::valueField().'['.$group->id.']['.$field->id.']';
                $scalar = $field->item instanceof Field;

                $controlProps[] = new FieldControlProps(
                    fieldId: $field->id,
                    type: $field->type(),
                    styled: null === $ui || $ui->styled,
                    label: $field->label ?? $field->id,
                    // A scalar item submits one list; a composite item submits
                    // one member-keyed record per position.
                    inputName: $base.($scalar ? '[]' : ''),
                    inputId: 'mahout-field-'.$field->id,
                    emptyLabel: $field->emptyLabel(),
                    error: $errors[$field->id] ?? null,
                    items: $this->reader->items($field->id, $object),
                    rows: $this->repeaterRows($field, $base, 'mahout-field-'.$field->id, $this->reader->items($field->id, $object), $errors),
                    layout: $field->layout,
                );

                continue;
            }

            $controlProps[] = new FieldControlProps(
                fieldId: $field->id,
                type: $field->type(),
                styled: null === $ui || $ui->styled,
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
