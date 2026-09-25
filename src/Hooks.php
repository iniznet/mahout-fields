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
     * The per-page load action's prefix. The one permitted dynamic hook form's
     * prefix, constructed at exactly one site, through screenLoad().
     */
    private const string SCREEN_LOAD_PREFIX = 'load-';

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
     * Core's save_post action: the classic editor path the save lifecycle is
     * attached to, at priority 10 with accepted_args 3. A core hook, declared
     * here because the raw-hook-name ban applies to core hooks as much as to
     * mahout ones; Admin\FieldsUiProvider attaches its handler through it.
     *
     * @since 1.0
     *
     * @action
     *
     * @param int     $postId the saved post's id
     * @param WP_Post $post   the saved post
     * @param bool    $update whether this is an existing post being updated
     */
    public const string SAVE_POST = 'save_post';

    /**
     * Core's metabox registration action. A core hook, declared here for the
     * same reason as save_post: Admin\FieldsUiProvider attaches through it, and
     * Admin\FieldMetabox registers the box each pair names.
     *
     * @since 1.0
     *
     * @action
     *
     * @param string  $postType the screen's post type
     * @param WP_Post $post     the post being edited
     */
    public const string ADD_META_BOXES = 'add_meta_boxes';

    /**
     * Core's REST init action, on which the field route and the per-post-type
     * register_rest_field reads are bound. A core hook, declared here for the
     * same reason as save_post; Admin\FieldsUiProvider attaches through it.
     *
     * @since 1.0
     *
     * @action
     */
    public const string REST_API_INIT = 'rest_api_init';

    /**
     * Core's admin menu action, on which one submenu page per declared option
     * screen is registered. A core hook, declared here for the same reason as
     * save_post; Admin\FieldsUiProvider attaches Admin\OptionScreenManager
     * through it, and the manager registers a page only for a screen the
     * current user's capability reaches -- a screen the user cannot reach is
     * not registered at all, never rendered without values. A screen whose
     * tabs carry no field group registers no save entry with it: nothing can
     * be submitted to a documentation page.
     *
     * @since 1.0
     *
     * @action
     */
    public const string ADMIN_MENU = 'admin_menu';

    /**
     * Core's enqueue point for the admin screens. The package's default
     * stylesheet is enqueued here, on exactly the screens whose request renders
     * field UI -- a panel's post type edit screen, a declared option screen.
     *
     * @action admin_enqueue_scripts
     */
    public const string ADMIN_ENQUEUE_SCRIPTS = 'admin_enqueue_scripts';

    /**
     * Core's admin notice action, on which a queued write failure is surfaced
     * to the user who submitted the form. A core hook, declared here for the
     * same reason as save_post; Admin\FieldsUiProvider attaches through it.
     *
     * @since 1.0
     *
     * @action
     */
    public const string ADMIN_NOTICES = 'admin_notices';

    /**
     * Core's personal-data exporter registration filter. The package's
     * exporter joins it at register() time.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters
     */
    public const string PERSONAL_DATA_EXPORTERS = 'wp_privacy_personal_data_exporters';

    /**
     * Core's personal-data eraser registration filter.
     *
     * @since 1.0
     *
     * @filter
     *
     * @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers
     */
    public const string PERSONAL_DATA_ERASERS = 'wp_privacy_personal_data_erasers';

    /**
     * The one dynamic hook form the option screens need, and the one site it
     * is constructed: core's per-page load action, built from the hook suffix
     * add_submenu_page() returned. Admin\OptionScreenManager attaches the
     * page's save entry through it.
     *
     * @action
     *
     * @param string $pageHook the hook suffix the screen was registered under
     */
    public static function screenLoad(string $pageHook): string
    {
        return self::SCREEN_LOAD_PREFIX.$pageHook;
    }

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
