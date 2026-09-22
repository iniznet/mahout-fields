<?php

declare(strict_types=1);

namespace Iniznet\Mahout\Fields\Contracts;

use Iniznet\Mahout\Fields\Admin\FieldEditorProps;
use Iniznet\Mahout\Fields\ObjectKind;

/**
 * The field panel's renderer: it projects a registered group onto typed
 * control props and renders them. The package's FieldEditor is the one
 * implementation; a host may replace it by binding its own to this contract.
 */
interface FieldEditor
{
    /**
     * The panel's props for one group on one object: the control props read
     * through the field layer, the expected-state hash, the nonce field markup
     * and the field-keyed error map.
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

    public function render(FieldEditorProps $props): string;
}
