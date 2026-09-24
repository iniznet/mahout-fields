<?php
/** @var Iniznet\Mahout\Fields\Admin\FieldControlProps $control */
?>
<div class="<?php echo esc_attr($control->classes('mahout-fields-control', 'mahout-fields-control--textarea')); ?>" data-field="<?php echo esc_attr($control->fieldId); ?>">
	<label for="<?php echo esc_attr($control->inputId); ?>"><?php echo esc_html($control->label); ?></label>
	<textarea id="<?php echo esc_attr($control->inputId); ?>"
		name="<?php echo esc_attr($control->inputName); ?>"
		rows="4"
		<?php if ($control->required) { ?>required="required" aria-required="true" <?php } ?>
		<?php if ($control->disabled) { ?>disabled="disabled" <?php } ?>
		<?php if (null !== $control->error) { ?>aria-invalid="true" aria-describedby="<?php echo esc_attr($control->inputId); ?>-error" <?php } ?>><?php echo esc_textarea($control->valueString()); ?></textarea>
	<?php if (null !== $control->error) { ?>
		<p class="<?php echo esc_attr($control->classes('mahout-fields-error')); ?>" id="<?php echo esc_attr($control->inputId); ?>-error"><?php echo esc_html($control->error); ?></p>
	<?php } elseif (null === $control->value && null !== $control->emptyLabel) { ?>
		<p class="<?php echo esc_attr($control->classes('mahout-fields-help')); ?>"><?php echo esc_html($control->emptyLabel); ?></p>
	<?php } ?>
</div>
