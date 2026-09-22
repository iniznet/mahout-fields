<?php
/** @var Iniznet\Mahout\Fields\Admin\FieldControlProps $control */
?>
<div class="mahout-fields-control mahout-fields-control--repeater" data-field="<?php echo esc_attr($control->fieldId); ?>">
	<label for="<?php echo esc_attr($control->inputId.'-0'); ?>"><?php echo esc_html($control->label); ?></label>
	<div class="mahout-fields-items">
	<?php $index = 0; ?>
	<?php foreach ($control->items as $item) { ?>
		<input type="text" id="<?php echo esc_attr($control->inputId.'-'.$index); ?>" name="<?php echo esc_attr($control->inputName); ?>" value="<?php echo esc_attr(null === $item ? '' : (string) $item); ?>" />
		<?php ++$index; ?>
	<?php } ?>
	</div>
	<?php if (null !== $control->error) { ?>
		<p class="mahout-fields-error" id="<?php echo esc_attr($control->inputId); ?>-error"><?php echo esc_html($control->error); ?></p>
	<?php } elseif ([] === $control->items && null !== $control->emptyLabel) { ?>
		<p class="mahout-fields-help"><?php echo esc_html($control->emptyLabel); ?></p>
	<?php } ?>
</div>
