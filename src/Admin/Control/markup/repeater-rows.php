<?php
/** @var Iniznet\Mahout\Fields\Admin\FieldControlProps $control */
?>
<div class="<?php echo esc_attr($control->classes('mahout-fields-control', 'mahout-fields-control--repeater', 'mahout-fields-repeater--rows')); ?>" data-field="<?php echo esc_attr($control->fieldId); ?>">
	<span class="<?php echo esc_attr($control->classes('mahout-fields-label')); ?>"><?php echo esc_html($control->label); ?></span>
	<div class="<?php echo esc_attr($control->classes('mahout-fields-items', 'mahout-fields-items--rows')); ?>">
	<?php $index = 0; ?>
	<?php foreach ($control->rows as $row) { ?>
		<div class="<?php echo esc_attr($control->classes('mahout-fields-item', 'mahout-fields-item--row')); ?>" data-position="<?php echo esc_attr((string) $index); ?>">
		<?php foreach ($row as $member) { ?>
			<?php echo $member->render(); // each member escapes at its own outputs?>
		<?php } ?>
		</div>
		<?php ++$index; ?>
	<?php } ?>
	</div>
	<?php if (null !== $control->error) { ?>
		<p class="<?php echo esc_attr($control->classes('mahout-fields-error')); ?>" id="<?php echo esc_attr($control->inputId); ?>-error"><?php echo esc_html($control->error); ?></p>
	<?php } elseif ([] === $control->rows && null !== $control->emptyLabel) { ?>
		<p class="<?php echo esc_attr($control->classes('mahout-fields-empty')); ?>"><?php echo esc_html($control->emptyLabel); ?></p>
	<?php } ?>
</div>
