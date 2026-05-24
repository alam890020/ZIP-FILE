/*!
 * MAKE SCHOOL — WP-Admin progressive enhancements.
 *
 * Module screens emit their own inline scripts for context-specific
 * interactions. This bundle handles only generic admin polish:
 *   - data-confirm guard on any "Delete" link.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); return; }
		document.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		document.querySelectorAll('[data-confirm]').forEach(function (el) {
			el.addEventListener('click', function (e) {
				if (!window.confirm(el.getAttribute('data-confirm') || 'Are you sure?')) {
					e.preventDefault();
				}
			});
		});
	});
})();
