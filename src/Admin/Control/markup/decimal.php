<?php
/** @var Iniznet\Mahout\Fields\Admin\FieldControlProps $control */
?>
<div class="<?php echo esc_attr($control->classes('mahout-fields-control', 'mahout-fields-control--decimal')); ?>" data-field="<?php echo esc_attr($control->fieldId); ?>">
	<label for="<?php echo esc_attr($control->inputId); ?>"><?php echo esc_html($control->label); ?></label>
	<input type="number" step="0.000001"
		id="<?php echo esc_attr($control->inputId); ?>"
		name="<?php echo esc_attr($control->inputName); ?>"
		value="<?php echo esc_attr($control->valueString()); ?>"
		<?php if ($control->required) { ?>required="required" aria-required="true" <?php } ?>
		<?php if ($control->disabled) { ?>disabled="disabled" <?php } ?>
		<?php if (null !== $control->error) { ?>aria-invalid="true" aria-describedby="<?php echo esc_attr($control->inputId); ?>-error" <?php } ?>
	/>
	<?php if (null !== $control->error) { ?>
		<p class="<?php echo esc_attr($control->classes('mahout-fields-error')); ?>" id="<?php echo esc_attr($control->inputId); ?>-error"><?php echo esc_html($control->error); ?></p>
	<?php } elseif (null === $control->value && null !== $control->emptyLabel) { ?>
		<p class="<?php echo esc_attr($control->classes('mahout-fields-help')); ?>"><?php echo esc_html($control->emptyLabel); ?></p>
	<?php } ?>
</div>
