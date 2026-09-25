<?php
/** @var array{screen: Iniznet\Mahout\Fields\OptionScreen, tab: Iniznet\Mahout\Fields\OptionTab, sections: list<array{title: string, markup: string}>, action: string, hasFields: bool, tabParam: string} $view */
?>
<div class="wrap">
	<h1><?php echo esc_html($view['screen']->pageTitle); ?></h1>
<?php if ('' !== $view['screen']->description) { ?>
	<p class="description"><?php echo esc_html($view['screen']->description); ?></p>
<?php } ?>
<?php if (count($view['screen']->tabs) > 1) { ?>
	<nav class="nav-tab-wrapper">
<?php foreach ($view['screen']->tabs as $tab) { ?>
		<a href="<?php echo esc_url($view['action'].'&'.$view['tabParam'].'='.rawurlencode($tab->label)); ?>" class="nav-tab<?php echo $tab->label === $view['tab']->label ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($tab->label); ?></a>
<?php } ?>
	</nav>
<?php } ?>
<?php if ($view['hasFields']) { ?>
	<form method="post" action="<?php echo esc_url($view['action']); ?>">
		<input type="hidden" name="<?php echo esc_attr($view['tabParam']); ?>" value="<?php echo esc_attr($view['tab']->label); ?>">
<?php } ?>
<?php foreach ($view['sections'] as $section) { ?>
<?php if ('' !== $section['title']) { ?>
	<h2><?php echo esc_html($section['title']); ?></h2>
<?php } ?>
<?php echo $section['markup']; // each part's own markup, escaped at its own outputs?>
<?php } ?>
<?php if ($view['hasFields']) { ?>
<?php submit_button(__('Save fields', 'mahout-fields')); ?>
	</form>
<?php } ?>
</div>
