/**
 * The option page's tab switching. Every tab's panel renders on the one page
 * load, the inactive ones hidden, and a click swaps the visible panel without
 * a request; the URL and the form's tab field follow the visible panel, so
 * the save still writes exactly the tab the visitor is on. Without this
 * script the links navigate and the server renders the requested tab -- one
 * page, two paths, and no inline script anywhere.
 */

(function () {
	'use strict';

	var page = document.querySelector('.mahout-fields-page');

	if (!page) {
		return;
	}

	var links = Array.prototype.slice.call(page.querySelectorAll('[data-mahout-tab]'));
	var panels = Array.prototype.slice.call(page.querySelectorAll('[data-mahout-panel]'));
	var fields = Array.prototype.slice.call(page.querySelectorAll('[data-mahout-tab-field]'));

	function select(label, url) {
		links.forEach(function (link) {
			var current = link.getAttribute('data-mahout-tab') === label;

			if (current) {
				link.setAttribute('aria-current', 'true');
			} else {
				link.removeAttribute('aria-current');
			}

			link.classList.toggle('nav-tab-active', link.classList.contains('nav-tab') && current);
		});

		panels.forEach(function (panel) {
			if (panel.getAttribute('data-mahout-panel') === label) {
				panel.removeAttribute('hidden');
			} else {
				panel.setAttribute('hidden', '');
			}
		});

		fields.forEach(function (tabField) {
			tabField.value = label;
		});

		if (url && window.history && window.history.replaceState) {
			window.history.replaceState(null, '', url);
		}
	}

	links.forEach(function (link) {
		link.addEventListener('click', function (event) {
			event.preventDefault();
			select(link.getAttribute('data-mahout-tab'), link.getAttribute('href'));
		});
	});
}());
