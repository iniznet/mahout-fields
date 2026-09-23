<?php
/** @var array{screen: Iniznet\Mahout\Fields\OptionScreen, panel: string, action: string} $view */
?>
<div class="wrap">
	<h1><?php echo esc_html($view['screen']->pageTitle); ?></h1>
	<form method="post" action="<?php echo esc_url($view['action']); ?>">
<?php echo $view['panel']; // the panel's own markup, escaped at its own outputs?>
<?php submit_button(__('Save fields', 'mahout-fields')); ?>
	</form>
</div>
