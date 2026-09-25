<?php
/** @var array{screen: Iniznet\Mahout\Fields\OptionScreen, active: Iniznet\Mahout\Fields\OptionTab, panels: list<array{label: string, hidden: bool, sections: list<array{title: string, markup: string}>}>, action: string, hasFields: bool, tabParam: string} $view */
?>
<div class="wrap mahout-fields-page">
	<h1><?php echo esc_html($view['screen']->pageTitle); ?></h1>
<?php if ('' !== $view['screen']->description) { ?>
	<p class="description"><?php echo esc_html($view['screen']->description); ?></p>
<?php } ?>
<?php $multi = 1 < count($view['screen']->tabs); ?>
<?php if ($multi && Iniznet\Mahout\Fields\OptionScreenLayout::Sidebar === $view['screen']->layout) { ?>
	<div class="mahout-fields-page__body mahout-fields-page__grid">
		<nav class="mahout-fields-page__nav" aria-label="<?php echo esc_attr($view['screen']->pageTitle); ?>">
<?php foreach ($view['screen']->tabs as $tab) { ?>
			<a href="<?php echo esc_url($view['action'].'&'.$view['tabParam'].'='.rawurlencode($tab->label)); ?>" class="mahout-fields-page__nav-link" data-mahout-tab="<?php echo esc_attr($tab->label); ?>"<?php echo $tab->label === $view['active']->label ? ' aria-current="true"' : ''; ?>><?php echo esc_html($tab->label); ?></a>
<?php } ?>
		</nav>
		<div class="mahout-fields-page__content">
<?php } else { ?>
<?php if ($multi) { ?>
	<nav class="nav-tab-wrapper">
<?php foreach ($view['screen']->tabs as $tab) { ?>
		<a href="<?php echo esc_url($view['action'].'&'.$view['tabParam'].'='.rawurlencode($tab->label)); ?>" class="nav-tab<?php echo $tab->label === $view['active']->label ? ' nav-tab-active' : ''; ?>" data-mahout-tab="<?php echo esc_attr($tab->label); ?>"><?php echo esc_html($tab->label); ?></a>
<?php } ?>
	</nav>
<?php } ?>
	<div class="mahout-fields-page__body">
<?php } ?>
<?php if ($view['hasFields']) { ?>
		<form method="post" action="<?php echo esc_url($view['action']); ?>">
			<input type="hidden" name="<?php echo esc_attr($view['tabParam']); ?>" value="<?php echo esc_attr($view['active']->label); ?>" data-mahout-tab-field>
<?php } ?>
<?php foreach ($view['panels'] as $panel) { ?>
			<div class="mahout-fields-page__panel" data-mahout-panel="<?php echo esc_attr($panel['label']); ?>"<?php echo $panel['hidden'] ? ' hidden' : ''; ?>>
<?php foreach ($panel['sections'] as $section) { ?>
				<div class="mahout-fields-page__section">
<?php if ('' !== $section['title']) { ?>
					<h2><?php echo esc_html($section['title']); ?></h2>
<?php } ?>
<?php echo $section['markup']; // each part's own markup, escaped at its own outputs?>
				</div>
<?php } ?>
			</div>
<?php } ?>
<?php if ($view['hasFields']) { ?>
<?php submit_button(__('Save fields', 'mahout-fields')); ?>
		</form>
<?php } ?>
<?php if ($multi && Iniznet\Mahout\Fields\OptionScreenLayout::Sidebar === $view['screen']->layout) { ?>
		</div>
	</div>
<?php } else { ?>
	</div>
<?php } ?>
</div>
