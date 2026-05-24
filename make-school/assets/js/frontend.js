/*!
 * MAKE SCHOOL — Front-end progressive enhancements.
 *
 * Currently scoped to UX niceties:
 *   - Confirm dismiss for any element marked data-confirm.
 *   - Auto-resize textareas on input for cleaner authoring.
 *
 * Module-level interactivity (attendance grid, LMS form) is owned by
 * inline scripts emitted by the corresponding render method, so this
 * bundle stays small and dependency-free.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); return; }
		document.addEventListener('DOMContentLoaded', fn);
	}

	function bindConfirms() {
		document.querySelectorAll('[data-confirm]').forEach(function (el) {
			if (el.dataset.msBound) { return; }
			el.dataset.msBound = '1';
			el.addEventListener('click', function (e) {
				var msg = el.getAttribute('data-confirm') || 'Are you sure?';
				if (!window.confirm(msg)) { e.preventDefault(); }
			});
		});
	}

	function autosize(el) {
		el.style.height = 'auto';
		el.style.height = (el.scrollHeight + 2) + 'px';
	}
	function bindAutosize() {
		document.querySelectorAll('.make-school-form textarea, .make-school-admission-form textarea').forEach(function (el) {
			if (el.dataset.msAutosize) { return; }
			el.dataset.msAutosize = '1';
			el.addEventListener('input', function () { autosize(el); });
			autosize(el);
		});
	}

	ready(function () {
		bindConfirms();
		bindAutosize();
	});
})();
