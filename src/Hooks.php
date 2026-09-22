<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields;

/**
 * Every hook mahout-fields emits or declares. Names are declared once, here.
 *
 * The per-field value filter is the one permitted dynamic form. Its full name
 * is constructed at exactly one site -- the field reader -- through
 * perFieldValue(); nothing inline composes it anywhere else.
 */
final class Hooks
{
    /**
     * Fires after the provider has registered the registry, before any group
     * is declared. This is where a consumer's module declares its groups.
     *
     * @since 1.0
     *
     * @action
     *
     * @param Contracts\FieldRegistry $registry the live registry
     */
    public const string REGISTRY_LOADED = 'mahout/fields/registry_loaded';

    /**
     * Filters the editor control map. Declared in this slice; the editor
     * controls themselves are the next slice's, and nothing fires this yet.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param array<string, class-string> $controls the field-type to control-class map
     */
    public const string EDITOR_CONTROLS = 'mahout/fields/editor_controls';

    /**
     * Fires after a field group is registered.
     *
     * @since 1.0
     *
     * @action
     *
     * @param FieldGroup $group the registered group
     */
    public const string GROUP_REGISTERED = 'mahout/fields/group_registered';

    /**
     * Filters a value after the field's own sanitisation, before it is stored.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param string|int|float|bool|null $value    the sanitised value
     * @param Field                      $field    the field declaration
     * @param int                        $objectId the object the value is written to
     */
    public const string SANITIZED_VALUE = 'mahout/fields/sanitized_value';

    /**
     * Filters a field's value on read, in the field's declared PHP shape.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param string|int|float|bool|null $value    the value, or null when absent
     * @param string                     $fieldId  the field id
     * @param ObjectContext              $context  the object context the read named
     * @param int                        $objectId the object the value is read from
     */
    public const string VALUE = 'mahout/fields/value';

    /**
     * The per-field value filter's prefix. The one permitted dynamic form;
     * constructed at exactly one site, the reader, through perFieldValue().
     */
    private const string VALUE_PER_FIELD = 'mahout/fields/value/id=';

    /**
     * Fires before a value is written. The value argument is the caller's raw
     * input, unsanitised; the save lifecycle's guards sit outside this hook.
     *
     * @since 1.0
     *
     * @action
     *
     * @param string $fieldId  the field id
     * @param mixed  $value    the raw value or item list about to be stored
     * @param Field  $field    the field declaration
     * @param int    $objectId the object the value is written to
     */
    public const string BEFORE_SAVE = 'mahout/fields/before_save';

    /**
     * Fires after a value is stored, with the same arguments as before_save.
     *
     * @since 1.0
     *
     * @action
     *
     * @param string $fieldId  the field id
     * @param mixed  $value    the raw value or item list that was stored
     * @param Field  $field    the field declaration
     * @param int    $objectId the object the value was written to
     */
    public const string AFTER_SAVE = 'mahout/fields/after_save';

    /**
     * Fires before a field's stored value is deleted.
     *
     * @since 1.0
     *
     * @action
     *
     * @param string $fieldId  the field id
     * @param Field  $field    the field declaration
     * @param int    $objectId the object the value is deleted from
     */
    public const string BEFORE_DELETE = 'mahout/fields/before_delete';

    /**
     * Fires after a field's stored value is deleted.
     *
     * @since 1.0
     *
     * @action
     *
     * @param string $fieldId  the field id
     * @param Field  $field    the field declaration
     * @param int    $objectId the object the value was deleted from
     */
    public const string AFTER_DELETE = 'mahout/fields/after_delete';

    /**
     * Filters a field's resolved storage target at registration. This is what
     * makes a meta-to-table migration possible from outside the library, and
     * it is correspondingly dangerous: a wrong return is refused loudly.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param StorageTarget $storage the target the field declared, or a filter changed
     * @param Field         $field   the field declaration
     */
    public const string STORAGE_TARGET = 'mahout/fields/storage_target';

    /**
     * Core's revision-restore action, observed at priority 20 by the package's
     * rehydrator: core's own meta restore runs at 10, and the table is
     * rehydrated from the restored mirror after it. This is a core hook, not a
     * mahout one; it is declared here because the rule that bans a raw hook
     * name applies to a core hook as much as to a mahout one.
     *
     * @since 1.0
     *
     * @action
     *
     * @param int $postId     the post the revision was restored onto
     * @param int $revisionId the revision that was restored
     */
    public const string RESTORE_POST_REVISION = 'wp_restore_post_revision';

    /**
     * The one permitted dynamic hook form, and the one site it is constructed.
     *
     * @filter
     *
     * @param string $fieldId the field id the filter is scoped to
     */
    public static function perFieldValue(string $fieldId): string
    {
        return self::VALUE_PER_FIELD.$fieldId;
    }
}
