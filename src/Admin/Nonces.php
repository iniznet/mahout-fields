<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Admin;

/**
 * The save lifecycle's nonce action and form-field names, declared once, here.
 *
 * A nonce action string literal at a wp_nonce_field() / wp_verify_nonce() /
 * check_admin_referer() call site is banned by the architecture rules, exactly
 * as a raw hook name is. The per-object binding is the one permitted dynamic
 * form, built here and nowhere else, mirroring Hooks::perFieldValue().
 *
 * The submitted form-field names are deliberately methods, not public
 * constants: they are form plumbing shared with the host's request adapter,
 * not hooks, and the hook reference gate treats every public string constant
 * as a hook -- a form field name must not enter that inventory.
 *
 * The REST route's cookie nonce is core's own (X-WP-Nonce / wp_rest); core
 * verifies it in rest_cookie_check_errors() and this class deliberately does
 * not name it: one owner, and the owner is core.
 */
final class Nonces
{
    /** The panel save action's base. The per-object form appends the object id. */
    private const string FIELD_PANEL_SAVE = 'mahout_fields_field_panel_save';

    /** The option screen save action's base. The per-screen form appends the page slug. */
    private const string OPTION_SCREEN_SAVE = 'mahout_fields_option_screen_save';

    /** The form field every panel carries, and the foreign-form guard's key. */
    private const string NONCE_FIELD = 'mahout_fields_panel_nonce';

    /** The submitted values' array key, shared with the host's request adapter. */
    private const string VALUE_FIELD = 'mahout_fields_panel';

    /** The expected-state hash's array key, likewise. */
    private const string HASH_FIELD = 'mahout_fields_hash';

    /** The one dynamic nonce action, built here and nowhere else. */
    public static function action(int $objectId): string
    {
        return self::FIELD_PANEL_SAVE.'_'.$objectId;
    }

    /**
     * The one dynamic nonce action an option screen's form carries, built here
     * and nowhere else: the screen's own admin-referer action, naming the page
     * slug, so one screen's form never verifies against another screen's save.
     */
    public static function screenAction(string $slug): string
    {
        return self::OPTION_SCREEN_SAVE.'_'.$slug;
    }

    /** The form field every panel carries. */
    public static function nonceField(): string
    {
        return self::NONCE_FIELD;
    }

    /** The submitted values' array field name. */
    public static function valueField(): string
    {
        return self::VALUE_FIELD;
    }

    /** The expected-state hash's array field name. */
    public static function hashField(): string
    {
        return self::HASH_FIELD;
    }
}
