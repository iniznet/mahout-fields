<?php
/** @var Iniznet\Mahout\Fields\Admin\FieldControlProps $control */
?>
<div class="mahout-fields-control mahout-fields-control--choice" data-field="<?php echo esc_attr($control->fieldId); ?>">
	<label for="<?php echo esc_attr($control->inputId); ?>"><?php echo esc_html($control->label); ?></label>
	<select
		id="<?php echo esc_attr($control->inputId); ?>"
		name="<?php echo esc_attr($control->inputName); ?>"
		<?php if ($control->required) { ?>required="required" aria-required="true" <?php } ?>
		<?php if ($control->disabled) { ?>disabled="disabled" <?php } ?>
		<?php if (null !== $control->error) { ?>aria-invalid="true" aria-describedby="<?php echo esc_attr($control->inputId); ?>-error" <?php } ?>>
		<?php if (null === $control->value) { ?>
		<option value=""><?php echo esc_html($control->emptyLabel ?? ''); ?></option>
		<?php } ?>
	<?php foreach ($control->options as $option) { ?>
		<option value="<?php echo esc_attr($option); ?>"<?php echo (string) $control->value === $option ? ' selected="selected"' : ''; ?>><?php echo esc_html($option); ?></option>
	<?php } ?>
	</select>
	<?php if (null !== $control->error) { ?>
		<p class="mahout-fields-error" id="<?php echo esc_attr($control->inputId); ?>-error"><?php echo esc_html($control->error); ?></p>
	<?php } ?>
</div>
