/**
 * WooBookings Custom: dated host exceptions in the product editor.
 *
 * After the date changes, the time select receives the schedule slots for that weekday
 * (data-slots from the server). The weekday comes from UTC arithmetic over the YYYY-MM-DD parts,
 * the same rule as hostForSlot() in the grid, so a daylight saving change cannot shift it.
 * Existing rows have their options rendered by the server: this script is a convenience, not a
 * condition for a correct save.
 */
(function () {
	'use strict';

	function weekday(dateValue) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateValue || '');
		if (!m) {
			return 0;
		}
		var dow = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])).getUTCDay();
		return dow === 0 ? 7 : dow;
	}

	/* No `min` attribute: an invalid date field would silently block "Update" while the General
	   panel is hidden. A past date gets a hint, and the server skips it anyway. */
	function fillTimes(row, slots, noSlotText, pastText, today) {
		var date = row.querySelector('.wbc-host-exc__date');
		var select = row.querySelector('.wbc-host-exc__time');
		if (!date || !select) {
			return;
		}
		var keep = select.value;
		var past = date.value && today && date.value < today;
		var times = past ? [] : slots[String(weekday(date.value))] || [];
		select.textContent = '';
		if (!times.length) {
			var none = document.createElement('option');
			none.value = '';
			none.textContent = past ? pastText : date.value ? noSlotText : '';
			select.appendChild(none);
			select.disabled = true;
			return;
		}
		select.disabled = false;
		times.forEach(function (t) {
			var opt = document.createElement('option');
			opt.value = t;
			opt.textContent = t;
			opt.selected = t === keep;
			select.appendChild(opt);
		});
	}

	function init(box) {
		var slots = {};
		try {
			slots = JSON.parse(box.getAttribute('data-slots') || '{}') || {};
		} catch (err) {
			slots = {};
		}
		var noSlotText = box.getAttribute('data-no-slot') || '';
		var pastText = box.getAttribute('data-past') || '';
		var today = box.getAttribute('data-today') || '';
		var rows = box.querySelector('.wbc-host-exc__rows');
		var template = box.querySelector('.wbc-host-exc__template');
		var add = box.querySelector('.wbc-host-exc__add');
		var next = parseInt(add ? add.getAttribute('data-next') : '0', 10) || 0;

		box.addEventListener('change', function (e) {
			if (e.target.classList.contains('wbc-host-exc__date')) {
				fillTimes(e.target.closest('.wbc-host-exc__row'), slots, noSlotText, pastText, today);
			}
		});

		box.addEventListener('click', function (e) {
			if (e.target.classList.contains('wbc-host-exc__remove')) {
				e.preventDefault();
				e.target.closest('.wbc-host-exc__row').remove();
			}
		});

		if (add && template && rows) {
			add.addEventListener('click', function (e) {
				e.preventDefault();
				var row = template.content.querySelector('.wbc-host-exc__row');
				row = row ? row.cloneNode(true) : null;
				if (row) {
					var named = row.querySelectorAll('[name]');
					for (var i = 0; i < named.length; i++) {
						named[i].name = named[i].name.replace('__i__', String(next));
					}
					next++;
					rows.appendChild(row);
					fillTimes(row, slots, noSlotText, pastText, today);
					var date = row.querySelector('.wbc-host-exc__date');
					if (date) {
						date.focus();
					}
				}
			});
		}
	}

	function boot() {
		var boxes = document.querySelectorAll('.wbc-host-exc');
		for (var i = 0; i < boxes.length; i++) {
			init(boxes[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
