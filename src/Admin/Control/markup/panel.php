<?php
/** @var array{props: Iniznet\Mahout\Fields\Admin\FieldEditorProps, controls: list<string>} $panel */
?>
<div class="mahout-fields-panel" data-mahout-group="<?php echo esc_attr($panel['props']->groupId); ?>" data-object-kind="<?php echo esc_attr((string) $panel['props']->objectKind->value); ?>" data-object-id="<?php echo esc_attr((string) $panel['props']->objectId); ?>">
	<input type="hidden" name="<?php echo esc_attr(Iniznet\Mahout\Fields\Admin\Nonces::hashField()); ?>[<?php echo esc_attr($panel['props']->groupId); ?>]" value="<?php echo esc_attr($panel['props']->expectedHash); ?>" />
	<?php echo $panel['props']->nonceField; // core's own wp_nonce_field() output?>
<?php foreach ($panel['controls'] as $controlMarkup) { ?>
	<?php echo $controlMarkup; // each control escaped exactly once at its own output?>
<?php } ?>
</div>
