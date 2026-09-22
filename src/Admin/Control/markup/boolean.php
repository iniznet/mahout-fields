<?php
/** @var Iniznet\Mahout\Fields\Admin\FieldControlProps $control */
?>
<div class="mahout-fields-control mahout-fields-control--boolean" data-field="<?php echo esc_attr($control->fieldId); ?>">
	<label for="<?php echo esc_attr($control->inputId); ?>"><?php echo esc_html($control->label); ?></label>
	<input type="checkbox" value="1"
		id="<?php echo esc_attr($control->inputId); ?>"
		name="<?php echo esc_attr($control->inputName); ?>"
		<?php if ($control->value) { ?>checked="checked" <?php } ?>
		<?php if ($control->required) { ?>required="required" aria-required="true" <?php } ?>
		<?php if ($control->disabled) { ?>disabled="disabled" <?php } ?>
		<?php if (null !== $control->error) { ?>aria-invalid="true" aria-describedby="<?php echo esc_attr($control->inputId); ?>-error" <?php } ?>
	/>
	<?php if (null !== $control->error) { ?>
		<p class="mahout-fields-error" id="<?php echo esc_attr($control->inputId); ?>-error"><?php echo esc_html($control->error); ?></p>
	<?php } elseif (!$control->value && null !== $control->emptyLabel) { ?>
		<p class="mahout-fields-help"><?php echo esc_html($control->emptyLabel); ?></p>
	<?php } ?>
</div>
