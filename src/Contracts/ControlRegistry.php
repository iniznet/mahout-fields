<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Exception\InvalidFieldDefinition;
use Iniznet\Mahout\Fields\FieldType;

/**
 * The editor control registry: field type to control, resolved by the field
 * editor and by nothing else. The package's `Admin\FieldTypeRegistry` is the
 * implementation; a host replaces a control through the
 * `mahout/fields/editor_controls` filter rather than by binding another
 * registry, so the map is built once and in one way.
 *
 * The seam exists so a consumer depends on the lookup and not on the class
 * that filters the declaration into existence: the registry resolves a
 * collaborator at construction, which is exactly what an injected value must
 * not be assumed to do.
 *
 * A type is named by its enum case or by that case's value, because a filter
 * result is keyed by the string and a declaration carries the enum. Both forms
 * answer to the same control, and neither is a second path to it.
 */
interface ControlRegistry
{
    /**
     * The control serving one field type.
     *
     * @throws InvalidFieldDefinition when no control serves the type
     */
    public function control(FieldType|string $type): FieldControl;

    /**
     * Whether any control serves the type at all. The lookup that refuses;
     * this one only reports, so a caller that renders conditionally never
     * catches an exception to ask a question.
     */
    public function has(FieldType|string $type): bool;
}
