<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Admin\FieldEditorProps;
use Iniznet\Mahout\Fields\ObjectKind;

/**
 * The field panel's renderer: it projects a registered group onto typed
 * control props and renders them. The package's FieldEditor is the one
 * implementation; a host may replace it by binding its own to this contract,
 * and one implementation then serves both the metabox panels and the option
 * screens -- there is no second renderer for the option context.
 */
interface FieldEditor
{
    /**
     * The panel's props for one group on one object: the control props read
     * through the field layer, the expected-state hash, the nonce field markup
     * and the field-keyed error map. A group whose context is not the object's
     * is refused before anything is projected.
     *
     * @param array<string, string> $errors field id to message for the current user
     */
    public function props(
        string $groupId,
        int $objectId,
        ObjectKind $objectKind,
        string $nonceField = '',
        array $errors = [],
    ): FieldEditorProps;

    /**
     * The panel's props for one option group. The option context addresses no
     * object: an option is a singleton read by key and never a row, so the
     * props carry no object kind and no object id, and a caller cannot supply
     * either. A group that is not option-context is refused before anything
     * is read.
     *
     * @param array<string, string> $errors field id to message for the current user
     */
    public function propsForGroup(string $groupId, string $nonceField = '', array $errors = []): FieldEditorProps;

    public function render(FieldEditorProps $props): string;
}
