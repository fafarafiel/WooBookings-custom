/**
 * WooBookings Custom, booking grid. Vanilla ES6, no dependencies and no build step.
 *
 * Reads window.WBCGrid (wp_localize_script). One fetch per 8-day PAGE to /products/slots,
 * partitioned by day in JS (switching days = zero network; revisiting a page = memo, zero
 * network). Pages are a disjoint partition indexed k=0..K-1 anchored at "today" — never a
 * clamped raw anchor (partition drift). Bucketing is TZ-safe: slots are keyed by the string
 * date in Europe/Warsaw from the payload; calendar arithmetic for page tiles runs on Y-m-d
 * strings through Date.UTC (DST-free by construction), labels come ONLY from the payload
 * lexicon (wp_date/WPML), never from Intl.
 */
(function () {
	'use strict';

	// Shared, dependency-free slot helpers (assets/slots.js), enqueued before this file.
	var SLOTS = (typeof window !== 'undefined' && window.WBCSlots) ? window.WBCSlots : null;
	if (!SLOTS) { return; }

	var CFG = window.WBCGrid;
	if (!CFG || !CFG.days || !CFG.products || !CFG.days.length) {
		return;
	}

	var WARSAW = 'Europe/Warsaw';

	/* ---------------------------------------------------------------------- helpers */

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined && text !== null) {
			node.textContent = text;
		}
		return node;
	}

	function i18n(key, fallback) {
		return (CFG.i18n && CFG.i18n[key]) ? CFG.i18n[key] : fallback;
	}

	// Shop-timezone parts, independent of the browser's own zone. See assets/slots.js.
	function warsawParts(dateObj) {
		return SLOTS.zonedParts(dateObj, WARSAW);
	}

	// "now" as a naive local ISO string in Warsaw — used for lexicographic "past" comparison.
	function warsawNowIso() {
		var p = warsawParts(new Date());
		return p.year + '-' + p.month + '-' + p.day + 'T' + p.hour + ':' + p.minute + ':' + p.second;
	}

	// See assets/slots.js: a raw string is never passed through new Date().
	function slotIso(slot) {
		return SLOTS.slotIso(slot, WARSAW);
	}

	function slotDayKey(slot) {
		return slotIso(slot).slice(0, 10);
	}

	function slotTime(slot) {
		return slotIso(slot).slice(11, 16);
	}

	function toInt(value, fallback) {
		return SLOTS.toInt(value, fallback);
	}

	/* ------------------------------------------------------------------------ state */

	var roots = {};
	var buckets = {};        // dayKey -> [slot, ...]
	var days = CFG.days;     // tiles of the CURRENT page; page 0 mirrors the server payload
	var selectedKey = CFG.days[0].key;
	var modal = null;
	var lastTrigger = null;
	var pendingApply = null; // post-add re-render deferred while the modal is open
	var viewLoading = false; // a nav fetch is in flight — buckets belong to the PREVIOUS page
	var announceToken = 0;   // stale-rAF guard for same-text re-announcements
	// Monotonic view counter. Incremented on EVERY view change — navigation with a fetch,
	// navigation served from memo (a memo-hit is not a request, but it IS a newer view and
	// must invalidate anything in flight), the initial load and the post-add refetch. Only a
	// result carrying the current epoch may touch the DOM or be announced.
	var slotsEpoch = 0;

	var PAGE_DAYS = CFG.days.length || 8;

	// Feature-guard (both staleness directions): a payload without the navigation lexicon
	// keeps the grid in legacy single-page mode — controls stay hidden, no TypeError.
	var NAV_OK = !!(CFG.todayKey && CFG.horizonEndKey &&
		CFG.weekdays && CFG.weekdays.length === 7 &&
		CFG.months && CFG.months.length);

	// Language for plural rules and capitalization; the payload lang can be '' without WPML —
	// fall back to the document language instead of silently degrading Polish plurals.
	var LANG = String(CFG.lang || document.documentElement.lang || '').slice(0, 2).toLowerCase();

	var nav = null;          // { prev, next } once initNav() takes over; null = legacy mode
	var navState = {
		today: CFG.todayKey || CFG.days[0].key,  // effective "today" (partition anchor)
		k: 0,                                    // current page index
		memo: {}                                 // pageIndex -> records (2xx responses ONLY)
	};

	/* ------------------------------------------------------------- calendar arithmetic */

	function pad2(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function dateToUtc(key) {
		var p = key.split('-');
		return Date.UTC(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
	}

	function utcToKey(ms) {
		var d = new Date(ms);
		return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate());
	}

	function addDays(key, n) {
		return utcToKey(dateToUtc(key) + n * 86400000);
	}

	// dni(a → b) = b − a in calendar days; the page model reads the range INCLUSIVE.
	function daysBetween(a, b) {
		return Math.round((dateToUtc(b) - dateToUtc(a)) / 86400000);
	}

	// Effective "today": the payload key, advanced by the browser clock in Warsaw when the tab
	// lives past midnight (Intl in the site TZ is already the trusted source for "past" here).
	function effectiveTodayKey() {
		var client = warsawNowIso().slice(0, 10);
		return client > (CFG.todayKey || '') ? client : CFG.todayKey;
	}

	/* ------------------------------------------------------------------------ fetch */

	/*
	 * A failure (HTTP !ok, network throw, broken JSON) resolves to {ok:false} — DISTINCT from a
	 * legally empty calendar. On the navigation path an empty week is meaningful ("no slots"
	 * on this day) while an error must say so and must never be memoized; collapsing both to
	 * empty records would render a lying "no sessions" for a transient 500 and freeze it in
	 * memo for the whole session.
	 */
	function fetchSlots(minKey, maxExclKey) {
		var url = CFG.restUrl +
			'?product_ids=' + encodeURIComponent(CFG.productIds.join(',')) +
			'&min_date=' + encodeURIComponent(minKey) +
			'&max_date=' + encodeURIComponent(maxExclKey) +
			'&hide_unavailable=false';

		return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (res) {
				if (!res.ok) {
					return { ok: false, records: [] };
				}
				return res.json().then(
					function (data) { return { ok: true, records: extractRecords(data) }; },
					function () { return { ok: false, records: [] }; }
				);
			})
			.catch(function () {
				return { ok: false, records: [] };
			});
	}

	function extractRecords(data) {
		if (!data) {
			return [];
		}
		if (Array.isArray(data)) {
			return data;
		}
		if (Array.isArray(data.records)) {
			return data.records;
		}
		return [];
	}

	function bucketize(records) {
		var map = {};
		for (var d = 0; d < days.length; d++) {
			map[days[d].key] = [];
		}
		for (var i = 0; i < records.length; i++) {
			var slot = records[i];
			var key = slotDayKey(slot);
			if (map[key]) {
				map[key].push(slot);
			}
		}
		Object.keys(map).forEach(function (key) {
			map[key].sort(function (a, b) {
				return slotIso(a).localeCompare(slotIso(b));
			});
		});
		return map;
	}

	/* --------------------------------------------------------------- pages + day picker */

	// K pages partition [today, horizonEnd] INCLUSIVE — floor(diff/8)+1, so the horizon day
	// itself is reachable on page K-1 even when diff is an exact multiple of the page width.
	function pageCount() {
		var diff = daysBetween(navState.today, CFG.horizonEndKey);
		if (diff < 0) {
			return 1;
		}
		return Math.floor(diff / PAGE_DAYS) + 1;
	}

	// Tiles of page k. The last page renders ONLY days <= horizonEnd (may be shorter than 8).
	function pageDays(k) {
		var start = addDays(navState.today, k * PAGE_DAYS);
		var out = [];
		for (var i = 0; i < PAGE_DAYS; i++) {
			var key = addDays(start, i);
			if (key > CFG.horizonEndKey) {
				break;
			}
			out.push({ key: key, label: tileLabel(key) });
		}
		return out;
	}

	// Same shape the server builds for page 0: uppercase short weekday + day number, both from
	// the payload lexicon (weekdays indexed by getUTCDay, 0 = Sunday).
	function tileLabel(key) {
		var w = new Date(dateToUtc(key)).getUTCDay();
		return (CFG.weekdays[w] || '') + ' ' + parseInt(key.slice(8, 10), 10);
	}

	// Midnight rollover: the partition anchor moved, every page index means something new —
	// drop the memo wholesale and clamp k back into range.
	function syncToday() {
		var eff = effectiveTodayKey();
		if (eff && eff !== navState.today) {
			navState.today = eff;
			navState.memo = {};
			var last = pageCount() - 1;
			if (navState.k > last) {
				navState.k = last;
			}
		}
	}

	function currentRange() {
		return {
			min: days[0].key,
			// The endpoint's max_date is EXCLUSIVE — the bound is the day AFTER the
			// last tile, or the eighth day would be permanently empty (an off-by-one).
			maxExcl: addDays(days[days.length - 1].key, 1)
		};
	}

	function setListLoading() {
		roots.list.setAttribute('aria-busy', 'true');
		roots.list.textContent = '';
		roots.list.appendChild(el('p', 'wbc-grid__loading', i18n('loading', 'Loading slots…')));
	}

	function renderListError() {
		roots.list.setAttribute('aria-busy', 'false');
		roots.list.textContent = '';
		roots.list.appendChild(el('p', 'wbc-grid__error', i18n('error', 'Could not load slots. Please refresh the page.')));
	}

	/*
	 * The single entry point for every view of a page: init (k=0), arrows, and later the month
	 * select. Epoch increments BEFORE any async work — also on a memo-hit, whose synchronous
	 * render must invalidate an in-flight fetch of another page (otherwise that fetch lands
	 * over this view: tiles of page A, slots of page B, every day "empty").
	 */
	function showPage(k, selectKey) {
		syncToday();
		// NaN never becomes a page index (a devtools-mangled select value reaches here via
		// daysBetween) — Math.min/max pass NaN through and every later comparison goes false.
		k = parseInt(k, 10);
		if (isNaN(k)) {
			k = 0;
		}
		var last = pageCount() - 1;
		k = Math.max(0, Math.min(last, k));
		var epoch = ++slotsEpoch;
		// A view change supersedes any refetch parked under the modal — applying it later
		// would paint the OLD page's records over the new page.
		pendingApply = null;
		// Focus safety net (D7): remember a focused grid element — Safari does not focus a
		// clicked button, so a re-render can detach the focused day tile and drop focus to
		// <body>. Restored below only when that actually happened.
		var hadFocus = roots.grid.contains(document.activeElement) ? document.activeElement : null;

		navState.k = k;
		days = pageDays(k);
		if (!days.length) {
			// Degenerate window (today past the horizon in a weeks-old tab): one honest tile
			// instead of a TypeError that kills the whole grid.
			days = [ { key: navState.today, label: tileLabel(navState.today) } ];
		}
		selectedKey = null;
		if (selectKey) {
			for (var i = 0; i < days.length; i++) {
				if (days[i].key === selectKey) {
					selectedKey = selectKey;
					break;
				}
			}
		}
		if (!selectedKey) {
			selectedKey = days[0].key;
		}

		renderDayPicker();
		renderNav();
		syncMonthSelect();

		if (hadFocus && !hadFocus.isConnected && document.activeElement === document.body) {
			var homeTab = roots.picker.querySelector('[data-key="' + cssEscape(selectedKey) + '"]');
			if (homeTab) {
				homeTab.focus();
			}
		}

		var memoized = navState.memo[String(k)];
		if (memoized) {
			viewLoading = false;
			buckets = bucketize(memoized);
			renderList();
			announce();
			return;
		}

		viewLoading = true;
		setListLoading();
		var range = currentRange();
		fetchSlots(range.min, range.maxExcl).then(function (res) {
			if (epoch !== slotsEpoch) {
				return;
			}
			viewLoading = false;
			if (!res.ok) {
				// Navigation distinguishes failure from emptiness; errors are never memoized,
				// so the next visit of this page retries with a fresh fetch. The status region
				// carries the same message — visually AND for AT (the list is not live).
				renderListError();
				announce(i18n('error', 'Could not load slots. Please refresh the page.'));
				return;
			}
			navState.memo[String(k)] = res.records;
			buckets = bucketize(res.records);
			renderList();
			// The visitor may have switched days mid-flight (a boundary page spans months).
			syncMonthSelect();
			announce();
		});
	}

	function renderNav() {
		if (!nav) {
			return;
		}
		setPagerState(nav.prev, navState.k > 0);
		setPagerState(nav.next, navState.k < pageCount() - 1);
	}

	// aria-disabled, never native disabled: the boundary is reached by repeatedly activating
	// the SAME arrow, so the control goes inactive UNDER the keyboard focus — a natively
	// disabled button would drop that focus to <body>.
	function setPagerState(btn, enabled) {
		if (enabled) {
			btn.removeAttribute('aria-disabled');
		} else {
			btn.setAttribute('aria-disabled', 'true');
		}
	}

	function onPagerActivate(delta, btn) {
		if (btn.getAttribute('aria-disabled') === 'true') {
			return;
		}
		showPage(navState.k + delta);
	}

	function initNav() {
		if (!NAV_OK) {
			return;
		}
		var prev = roots.grid.querySelector('.wbc-grid__pager--prev');
		var next = roots.grid.querySelector('.wbc-grid__pager--next');
		if (!prev || !next) {
			return;
		}
		nav = { prev: prev, next: next, month: null };
		// Unhiding is the JS handshake: markup born hidden stays hidden for a legacy payload
		// or a legacy bundle — no dead controls on the live shop, ever.
		prev.hidden = false;
		next.hidden = false;
		prev.addEventListener('click', function () { onPagerActivate(-1, prev); });
		next.addEventListener('click', function () { onPagerActivate(1, next); });

		var monthWrap = roots.grid.querySelector('.wbc-grid__month');
		var select = roots.grid.querySelector('.wbc-grid__month-select');
		if (monthWrap && select) {
			for (var i = 0; i < CFG.months.length; i++) {
				var opt = document.createElement('option');
				opt.value = CFG.months[i].key;
				opt.textContent = CFG.months[i].label;
				select.appendChild(opt);
			}
			select.addEventListener('change', onMonthChange);
			monthWrap.hidden = false;
			nav.month = select;
		}
	}

	/*
	 * Month jump: the page CONTAINING the month's first day (clamped to 0 — for the current
	 * month the 1st usually lies BEFORE today and the raw formula would give k=-1), with the
	 * auto-selected day = max(1st of month, effective today). The select then reflects the
	 * month of the SELECTED day, so picking the last month of the horizon never snaps back.
	 */
	function onMonthChange() {
		var first = nav.month.value + '-01';
		syncToday();
		var target = first > navState.today ? first : navState.today;
		var k = Math.max(0, Math.floor(daysBetween(navState.today, target) / PAGE_DAYS));
		showPage(k, target);
	}

	function syncMonthSelect() {
		if (nav && nav.month && selectedKey) {
			nav.month.value = selectedKey.slice(0, 7);
		}
	}

	/* -------------------------------------------------------- status announcements */

	// Plural forms: Polish uses all three (one, few, many); every other language uses one and many.
	function slotCountPhrase(n) {
		if (!n) {
			return i18n('statusNone', 'no slots');
		}
		var form;
		if (n === 1) {
			form = i18n('slotOne', '%d slot');
		} else if ('pl' === LANG && n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) {
			form = i18n('slotFew', '%d slots');
		} else {
			form = i18n('slotMany', '%d slots');
		}
		return form.replace('%d', String(n));
	}

	function monthGen(key) {
		var m = parseInt(key.slice(5, 7), 10);
		return (CFG.monthsGen && CFG.monthsGen[m - 1]) || '';
	}

	// "12-19 August" / "30 August - 6 September", with the year added only when it differs from the
	// current year (of the effective today).
	function rangeText() {
		var a = days[0].key;
		var b = days[days.length - 1].key;
		var curYear = navState.today.slice(0, 4);
		var aDay = parseInt(a.slice(8, 10), 10);
		var bDay = parseInt(b.slice(8, 10), 10);
		var bPart = bDay + ' ' + monthGen(b) + (b.slice(0, 4) !== curYear ? ' ' + b.slice(0, 4) : '');
		if (a.slice(0, 7) === b.slice(0, 7)) {
			return aDay + '–' + bDay + ' ' + monthGen(b) + (b.slice(0, 4) !== curYear ? ' ' + b.slice(0, 4) : '');
		}
		var aPart = aDay + ' ' + monthGen(a) + (a.slice(0, 4) !== curYear ? ' ' + a.slice(0, 4) : '');
		return aPart + ' – ' + bPart;
	}

	function capitalize(s) {
		return s ? s.charAt(0).toLocaleUpperCase(LANG || undefined) + s.slice(1) : s;
	}

	/*
	 * The ONLY writer of the status region (SC 4.1.3): range + selected day + slot count,
	 * boundary suffix on the edge pages. The cart toast stays a separate channel with its own
	 * writer. Identical consecutive text is cleared and re-set in rAF, or AT would not repeat.
	 */
	function announce(text) {
		if (!roots.status) {
			return;
		}
		if (undefined === text) {
			var w = new Date(dateToUtc(selectedKey)).getUTCDay();
			var count = roots.list.querySelectorAll('.wbc-grid__card').length;
			var dayPhrase = capitalize((CFG.weekdaysLong && CFG.weekdaysLong[w]) || '') + ' ' +
				selectedKey.slice(8, 10) + '.' + selectedKey.slice(5, 7) + ': ' + slotCountPhrase(count) + '.';
			text = rangeText() + '. ' + dayPhrase;
			if (nav) {
				if (navState.k === 0) {
					text += ' ' + i18n('statusStart', 'Earliest range.');
				} else if (navState.k >= pageCount() - 1) {
					text += ' ' + i18n('statusEnd', 'End of available slots.');
				}
			}
		}
		if (roots.status.textContent === text) {
			var tok = ++announceToken;
			roots.status.textContent = '';
			window.requestAnimationFrame(function () {
				if (tok === announceToken) {
					roots.status.textContent = text;
				}
			});
			return;
		}
		announceToken++;
		roots.status.textContent = text;
	}

	function renderDayPicker() {
		var picker = roots.picker;
		picker.textContent = '';

		days.forEach(function (day, index) {
			var tab = el('button', 'wbc-grid__day');
			tab.type = 'button';
			tab.setAttribute('role', 'tab');
			tab.dataset.key = day.key;
			var isSelected = day.key === selectedKey;
			tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
			tab.tabIndex = isSelected ? 0 : -1;
			if (isSelected) {
				tab.classList.add('wbc-grid__day--selected');
			}

			var parts = String(day.label).split(' ');
			tab.appendChild(el('span', 'wbc-grid__day-name', parts[0] || day.label));
			if (parts.length > 1) {
				tab.appendChild(el('span', 'wbc-grid__day-num', parts.slice(1).join(' ')));
			}

			tab.addEventListener('click', function () {
				selectDay(day.key);
			});
			tab.addEventListener('keydown', function (e) {
				onPickerKey(e, index);
			});

			picker.appendChild(tab);
		});
	}

	function onPickerKey(e, index) {
		var next = null;
		if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
			next = Math.min(days.length - 1, index + 1);
		} else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
			next = Math.max(0, index - 1);
		} else if (e.key === 'Home') {
			next = 0;
		} else if (e.key === 'End') {
			next = days.length - 1;
		} else {
			return;
		}
		e.preventDefault();
		var key = days[next].key;
		selectDay(key);
		var tab = roots.picker.querySelector('[data-key="' + cssEscape(key) + '"]');
		if (tab) {
			tab.focus();
		}
	}

	function cssEscape(value) {
		if (window.CSS && CSS.escape) {
			return CSS.escape(value);
		}
		return String(value).replace(/["\\]/g, '\\$&');
	}

	function selectDay(key) {
		selectedKey = key;
		var tabs = roots.picker.querySelectorAll('.wbc-grid__day');
		for (var i = 0; i < tabs.length; i++) {
			var selected = tabs[i].dataset.key === key;
			tabs[i].setAttribute('aria-selected', selected ? 'true' : 'false');
			tabs[i].tabIndex = selected ? 0 : -1;
			tabs[i].classList.toggle('wbc-grid__day--selected', selected);
		}
		if (viewLoading) {
			// The nav fetch is still in flight — buckets hold the PREVIOUS page. Rendering
			// them under this key would paint a lying "no slots" state (and announce it) for
			// the whole 2-4 s of a cold fetch. Keep the loading state; the landing fetch
			// renders and announces with the freshly selected day.
			setListLoading();
			syncMonthSelect();
			return;
		}
		renderList();
		// A day change is a view change the list can no longer announce itself (not a live
		// region) — the status region compensates, and the select follows the selected day's
		// month (a boundary page spans two months).
		syncMonthSelect();
		announce();
	}

	/* -------------------------------------------------------------------------- list */

	function renderList() {
		var list = roots.list;
		list.setAttribute('aria-busy', 'false');
		list.textContent = '';

		var slots = buckets[selectedKey] || [];
		var cards = [];

		for (var i = 0; i < slots.length; i++) {
			var card = buildCard(slots[i]);
			if (card) {
				cards.push(card);
			}
		}

		if (!cards.length) {
			list.appendChild(el('p', 'wbc-grid__empty', i18n('noSlots', 'No slots')));
			return;
		}
		cards.forEach(function (card) {
			list.appendChild(card);
		});
	}

	function slotState(slot, product) {
		var available = toInt(slot.available, 0);
		var minPersons = toInt(product.minPersons, 1);
		if (slotIso(slot) < warsawNowIso()) {
			return 'past';
		}
		if (available < minPersons) {
			return 'full';
		}
		// With person types the smallest party is the sum of the counters' starting values.
		if (hasPersonTypes(product) && personRowsTotal(personTypeRows(product)) > personLimit(product, available)) {
			return 'full';
		}
		return 'book';
	}

	/* Delegates to the shared slot module, which is where the date logic lives and where the
	   tests point. See assets/slots.js for why the weekday is computed the way it is. */
	function hostForSlot(product, slot) {
		return SLOTS.hostForSlot(product, slot, WARSAW, CFG.hostsById);
	}

	function buildCard(slot) {
		var product = CFG.products[String(slot.product_id)];
		if (!product) {
			return null;
		}

		// The endpoint can report a negative available (non-atomic add-to-cart racing the same
		// slot, or a capacity lowered under existing bookings — production shows available:-7 on
		// WPML-duplicated products). Never let arithmetic like that reach the card as "-7/20 seats";
		// clamp at zero, which also makes the slot read as full, exactly as it is.
		var available = Math.max(0, toInt(slot.available, 0));
		var booked = Math.max(0, toInt(slot.booked, 0));
		var total = available + booked;
		var state = slotState(slot, product);

		var card = el('article', 'wbc-grid__card wbc-grid__card--' + state);
		// Identity for focus restoration after a rerender (the booked slot's CTA gets focus back).
		card.dataset.slotIso = slotIso(slot);
		card.dataset.productId = String(product.id);

		// Time.
		var time = el('time', 'wbc-grid__time', slotTime(slot));
		time.setAttribute('datetime', slotIso(slot));
		card.appendChild(time);

		// Name (opens modal).
		var nameWrap = el('div', 'wbc-grid__name-wrap');
		var name = el('button', 'wbc-grid__name');
		name.type = 'button';
		name.textContent = product.label;
		name.setAttribute('aria-haspopup', 'dialog');
		name.addEventListener('click', function () {
			openModal(product, name);
		});
		nameWrap.appendChild(name);

		// Host line renders on PRESENCE OF DATA, never on `type === 'session'`. A type test would
		// silently skip a third product type if one were ever added; requiring the datum means
		// products with nobody assigned simply do not get the line.
		var host = hostForSlot(product, slot);
		if (host) {
			var hostLine = el('div', 'wbc-grid__host');
			hostLine.appendChild(el('span', 'wbc-grid__host-label', i18n('hostLabel', 'Host:')));
			var hostName = el('button', 'wbc-grid__host-name', host.name);
			hostName.type = 'button';
			hostName.setAttribute('aria-haspopup', 'dialog');
			hostName.addEventListener('click', function () {
				openModal({ label: host.name, description: host.bio, image: host.image }, hostName);
			});
			hostLine.appendChild(hostName);
			nameWrap.appendChild(hostLine);
		}

		card.appendChild(nameWrap);

		// Meta: duration + occupancy.
		var meta = el('div', 'wbc-grid__meta');
		meta.appendChild(el('span', 'wbc-grid__duration', product.durationText));
		var occ = el('span', 'wbc-grid__occupancy');
		occ.textContent = available + '/' + total + ' ' + i18n('seats', 'seats');
		meta.appendChild(occ);
		card.appendChild(meta);

		// Action zone.
		var action = el('div', 'wbc-grid__action');
		if (state === 'book') {
			action.appendChild(buildControlAndCta(slot, product, available));
		} else {
			action.appendChild(buildStatePill(state));
		}
		card.appendChild(action);

		return card;
	}

	function buildStatePill(state) {
		var label = state === 'past' ? i18n('past', 'Slot has passed') : i18n('full', 'Sold out');
		var pill = el('span', 'wbc-grid__pill wbc-grid__pill--' + state, label);
		return pill;
	}

	/* ----------------------------------------------------------- control + CTA (POST) */

	function buildControlAndCta(slot, product, available) {
		var wrap = el('div', 'wbc-grid__action-inner');

		// Classic form-POST to the native Woo add-to-cart handler — identical fields, identical
		// vendor validation. NEVER the Store API — that path drops persons. When the browser
		// can, the submit is intercepted and carried over fetch so the visitor stays on the
		// list; otherwise the native POST proceeds unchanged (progressive enhancement).
		var form = document.createElement('form');
		form.className = 'wbc-grid__form';
		form.method = 'post';
		form.action = product.productUrl || '';

		form.appendChild(hidden('add-to-cart', product.addToCartId));
		form.appendChild(hidden(product.fields.start, slot.date));

		// Person types (v1.2): one counter per type, each posting its own field. The single
		// persons field does not exist for such a product (fields.persons is '').
		// (A party too large for the free seats never gets here — slotState says 'full'.)
		var personGroup = null;
		if (hasPersonTypes(product)) {
			personGroup = buildPersonGroup(product, available, form, personTypeRows(product));
			wrap.appendChild(personGroup.node);
		} else {
			var personsHidden = hidden(product.fields.persons, '');
			form.appendChild(personsHidden);

			// Persons and duration are ORTHOGONAL, config-driven controls: either, both or
			// neither may exist. A control is appended only when it was built — with both fixed
			// the card renders just the CTA. Appending an undefined control here would throw
			// inside buildCard's loop and silently kill the whole list render.
			var pc = product.personsControl || {};
			if (pc.enabled) {
				wrap.appendChild(buildStepper(product, available, personsHidden));
			} else {
				personsHidden.value = String(pc.fixed || 1);
			}
		}

		var dc = product.durationControl || {};
		if (dc.enabled) {
			// Only customer-duration products post the duration field; fixed-duration
			// products must not send it (the native form never renders it for them).
			var durationHidden = hidden(product.fields.duration, '');
			form.appendChild(durationHidden);
			wrap.appendChild(buildDurationSelect(slot, product, durationHidden));
		}

		var cta = el('button', 'wbc-grid__cta');
		cta.type = 'submit';
		cta.textContent = i18n('book', 'Book');
		form.appendChild(cta);
		if (personGroup) {
			personGroup.bindCta(cta);
		}

		// Submit (not click) catches both the button and Enter in one guard. Only when the
		// whole toolchain exists — otherwise the native POST stays, still correct.
		if (window.fetch && window.DOMParser && window.URLSearchParams) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				submitBooking(form, cta, slot, product);
			});
		}

		wrap.appendChild(form);
		return wrap;
	}

	/* -------------------------------------------------------------- in-place add-to-cart */

	function readCartHashCookie() {
		var m = document.cookie.match(/(?:^|;\s*)woocommerce_cart_hash=([^;]*)/);
		return m ? m[1] : null;
	}

	/*
	 * Adding through fetch leaves the header cart counter stale: a native POST reloaded the page
	 * and we deliberately do not. WooCommerce keeps that counter in "fragments", chunks of HTML
	 * refreshed over AJAX. The primary path is the `wc_fragment_refresh` event, handled by the
	 * vendor's cart-fragments script, which is also what updates theme cart widgets. The manual
	 * fallback matters because caching and optimisation plugins routinely dequeue cart-fragments;
	 * with nobody listening, the counter would sit at zero and nothing would appear in the console.
	 */
	function refreshCartFragments() {
		try {
			if (window.jQuery && window.wc_cart_fragments_params) {
				window.jQuery(document.body).trigger('wc_fragment_refresh');
				return;
			}
		} catch (err) {
			// fall through to the fallback below
		}

		var params = window.wc_cart_fragments_params || window.wc_add_to_cart_params;
		if (!params || !params.wc_ajax_url || !window.fetch) {
			return;
		}
		fetch(params.wc_ajax_url.replace('%%endpoint%%', 'get_refreshed_fragments'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json' }
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (data) {
				if (!data || !data.fragments) {
					return;
				}
				Object.keys(data.fragments).forEach(function (selector) {
					var nodes = document.querySelectorAll(selector);
					for (var i = 0; i < nodes.length; i++) {
						/*
						 * Trust boundary: this is HTML that WooCommerce generated on the
						 * same origin, fetched with the authenticated session: byte for byte the
						 * markup the vendor's own cart-fragments script injects with replaceWith().
						 * Fragments ARE markup by definition (mini cart, counter), so escaping them
						 * to text would destroy the mechanism. Nothing user-supplied and nothing
						 * from another origin reaches this path.
						 */
						var tmp = document.createElement('div');
						tmp.innerHTML = data.fragments[selector];
						if (tmp.firstElementChild) {
							nodes[i].replaceWith(tmp.firstElementChild);
						}
					}
				});
			})
			.catch(function () {
				// The counter stays stale. That is cosmetic and must never take down an
				// add-to-cart that already succeeded.
			});
	}

	function showToast(kind, message, withCartLink) {
		if (!roots.toast) {
			return;
		}
		roots.toast.textContent = '';
		var toast = el('div', 'wbc-grid__toast wbc-grid__toast--' + kind);
		toast.appendChild(el('span', 'wbc-grid__toast-msg', message));
		if (withCartLink && CFG.cartUrl) {
			var link = document.createElement('a');
			link.className = 'wbc-grid__toast-link';
			link.href = CFG.cartUrl;
			link.textContent = i18n('goToCart', 'Go to cart');
			toast.appendChild(link);
		}
		var close = el('button', 'wbc-grid__toast-close', '×');
		close.type = 'button';
		close.setAttribute('aria-label', i18n('close', 'Close'));
		close.addEventListener('click', function () {
			roots.toast.textContent = '';
		});
		toast.appendChild(close);
		roots.toast.appendChild(toast);
	}

	// The submit disabled the focused CTA (focus falls to <body>) and the rerender detached it.
	// A keyboard user booking several slots in a row must not restart tabbing from the top of
	// the page: focus goes back to the same slot's CTA, or to the toast when the slot is no
	// longer bookable. Same defect class as focus lost on Escape, on the add to cart path.
	function restoreFocusAfterRender(slot, product) {
		var sel = '[data-slot-iso="' + cssEscape(slotIso(slot)) + '"][data-product-id="' + cssEscape(String(product.id)) + '"] .wbc-grid__cta';
		var cta = roots.list.querySelector(sel);
		if (cta) {
			cta.focus();
			return;
		}
		if (roots.toast) {
			roots.toast.focus();
		}
	}

	/*
	 * Post-add refetch of the CURRENT page (success and failure alike). The whole memo drops
	 * first — availability changed server-side and every cached page may now lie; other pages
	 * refetch lazily on their next visit. This path KEEPS the previous snapshot on an empty or
	 * failed response (unlike navigation): a transient REST failure right after a success toast
	 * would otherwise paint a sold-out week with no recovery, since selectDay never refetches.
	 * The server stays the truth at add time either way.
	 */
	function refreshCurrentAfterCart(slot, product) {
		navState.memo = {};
		var epoch = ++slotsEpoch;
		var range = currentRange();
		return fetchSlots(range.min, range.maxExcl).then(function (res) {
			if (epoch !== slotsEpoch) {
				// A newer view (navigation or another add) superseded this refetch — an older
				// snapshot landing last would resurrect seats the newer state already holds.
				return;
			}
			if (!res.ok || !res.records.length) {
				return;
			}
			if (nav) {
				navState.memo[String(navState.k)] = res.records;
			}
			var apply = function (deferred) {
				if (epoch !== slotsEpoch) {
					// Gate ALSO at application time: parked under the modal, this snapshot may
					// be superseded by a navigation before the modal closes.
					return;
				}
				buckets = bucketize(res.records);
				renderList();
				if (!deferred) {
					restoreFocusAfterRender(slot, product);
				}
			};
			if (modal && !modal.overlay.hidden) {
				// Applying under an open modal would detach lastTrigger, and closeModal()
				// would drop focus to <body>, so defer until the modal closes.
				pendingApply = apply;
				return;
			}
			apply(false);
		});
	}

	function submitBooking(form, cta, slot, product) {
		// A second click mid-flight would create a SECOND in-cart hold on the pool — the
		// in-flight flag plus the disabled CTA make the request single-shot.
		if (form.dataset.inflight === '1') {
			return;
		}
		form.dataset.inflight = '1';
		cta.disabled = true;
		// Adding to cart can be noticeably slow (a POST plus a server-side cart recalculation).
		// Without a busy signal the person clicking cannot tell whether anything happened. The
		// spinner carries that visually and aria-busy carries it for screen readers.
		cta.classList.add('wbc-grid__cta--busy');
		cta.setAttribute('aria-busy', 'true');

		// Same fields the native POST would carry (named, enabled controls only; the CTA and
		// the duration <select> are unnamed by design — hidden inputs hold their values).
		var params = new URLSearchParams();
		for (var i = 0; i < form.elements.length; i++) {
			var field = form.elements[i];
			if (field.name && !field.disabled) {
				params.append(field.name, field.value);
			}
		}

		var hashBefore = readCartHashCookie();

		fetch(form.action, { method: 'POST', body: params, credentials: 'same-origin' })
			.then(function (res) {
				return res.text();
			})
			.then(function (html) {
				var doc = null;
				var errText = '';
				try {
					doc = new DOMParser().parseFromString(html, 'text/html');
				} catch (err) {
					doc = null;
				}
				if (doc) {
					var errNode = doc.querySelector('.woocommerce-error');
					errText = errNode ? errNode.textContent.replace(/\s+/g, ' ').trim() : '';
				}

				// Primary success signal: the cart-hash cookie changed (wc_setcookie, readable
				// unless a site filter forces HttpOnly). Backup: parsed response carries a
				// .woocommerce-message and no .woocommerce-error — without it a successful add
				// under an HttpOnly cookie would read as failure, the visitor would click again
				// and hold the seats twice. Deliberately NOT judged by navigation shape
				// (redirect-to-cart on/off is irrelevant to either signal).
				var hashAfter = readCartHashCookie();
				var cookieSuccess = null !== hashAfter && hashAfter !== hashBefore;
				var domSuccess = doc && '' === errText && doc.querySelector('.woocommerce-message');

				if (cookieSuccess || domSuccess) {
					// The fallback copy deliberately omits the minute count. Hard-coding a number
					// here would start lying the moment WBC_Hold::MINUTES changes, and a thinner
					// message beats an untrue one.
					showToast('success', i18n('addedToCart', 'Added to cart.'), true);
					// Refresh the header counter without reloading the page.
					refreshCartFragments();
					return refreshCurrentAfterCart(slot, product);
				}
				showToast('error', errText || i18n('addError', 'Could not add to cart.'), false);
				// The FAILURE path refreshes too: a rejected add usually means the seats are
				// gone, and without a refetch the visitor keeps clicking a dead "book" CTA
				// forever (nothing else ever refreshes the counters).
				refreshCurrentAfterCart(slot, product);
			})
			.catch(function () {
				showToast('error', i18n('addError', 'Could not add to cart.'), false);
			})
			.then(function () {
				// Always unlock; after a successful re-render these nodes are detached, which
				// makes the unlock a harmless no-op.
				form.dataset.inflight = '';
				cta.disabled = false;
				cta.classList.remove('wbc-grid__cta--busy');
				cta.removeAttribute('aria-busy');
				// The disable dropped keyboard focus to <body>. On the FAILURE path the CTA is
				// still attached — give focus back so the visitor can retry from where they were
				// (the success path restores focus after the rerender instead).
				if (cta.isConnected && document.activeElement === document.body) {
					cta.focus();
				}
			});
	}

	function hidden(name, value) {
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = value === undefined || value === null ? '' : String(value);
		return input;
	}

	function buildStepper(product, available, target) {
		var min = toInt(product.personsControl.min, 1);
		var maxByAvail = Math.min(toInt(product.personsControl.max, available), available);
		var max = Math.max(min, maxByAvail);
		var value = min;
		target.value = String(value);

		var stepper = el('div', 'wbc-grid__stepper');
		stepper.setAttribute('role', 'group');
		stepper.setAttribute('aria-label', i18n('persons', 'People'));

		var minus = el('button', 'wbc-grid__step wbc-grid__step--minus', '−');
		minus.type = 'button';
		minus.setAttribute('aria-label', i18n('decrease', 'Fewer people'));

		var output = el('span', 'wbc-grid__step-value', String(value));
		output.setAttribute('aria-live', 'polite');

		var plus = el('button', 'wbc-grid__step wbc-grid__step--plus', '+');
		plus.type = 'button';
		plus.setAttribute('aria-label', i18n('increase', 'More people'));

		function sync() {
			output.textContent = String(value);
			target.value = String(value);
			minus.disabled = value <= min;
			plus.disabled = value >= max;
		}

		minus.addEventListener('click', function () {
			if (value > min) {
				value--;
				sync();
			}
		});
		plus.addEventListener('click', function () {
			if (value < max) {
				value++;
				sync();
			}
		});

		stepper.appendChild(minus);
		stepper.appendChild(output);
		stepper.appendChild(plus);
		sync();
		return stepper;
	}

	/* -------------------------------------------------------- person types (v1.2) */

	function hasPersonTypes(product) {
		return Array.isArray(product.personTypes) && product.personTypes.length > 0;
	}

	// Seats one booking may take: the product's party maximum, never more than what is free.
	function personLimit(product, available) {
		var maxTotal = toInt(product.maxPersons, 0);
		return maxTotal > 0 ? Math.min(maxTotal, available) : available;
	}

	// A typed party is never empty: with every type at min 0 and "min persons" 0 the vendor
	// would get no persons at all and skip both the minimum check and the person multiplier.
	function personMinTotal(product) {
		return Math.max(1, toInt(product.minPersons, 1));
	}

	function personRowsTotal(rows) {
		var sum = 0;
		for (var i = 0; i < rows.length; i++) {
			sum += rows[i].value;
		}
		return sum;
	}

	/* Starting value of every counter: the type's own minimum. When those minimums do not reach
	   the party minimum, the first type that already requires someone (min ≥ 1) takes the
	   difference, else the first type that still has room — so the card opens on a party the
	   cart accepts. */
	function personTypeRows(product) {
		var rows = product.personTypes.map(function (type) {
			var min = Math.max(0, toInt(type.min, 0));
			var max = type.max === null || type.max === undefined ? Infinity : Math.max(min, toInt(type.max, min));
			return { type: type, min: min, max: max, value: min };
		});
		var shortfall = personMinTotal(product) - personRowsTotal(rows);
		if (shortfall > 0) {
			var target = null;
			var i;
			for (i = 0; i < rows.length && !target; i++) {
				if (rows[i].min >= 1 && rows[i].max > rows[i].value) {
					target = rows[i];
				}
			}
			for (i = 0; i < rows.length && !target; i++) {
				if (rows[i].max > rows[i].value) {
					target = rows[i];
				}
			}
			if (target) {
				target.value = Math.min(target.max, target.value + shortfall);
			}
		}
		return rows;
	}

	function typeLabel(type) {
		return type.unitPriceText ? type.label + ' · ' + type.unitPriceText : type.label;
	}

	// '%s' substitution through a function: a type name with "$&" or "$$" must stay literal.
	function fillName(template, name) {
		return template.replace('%s', function () {
			return name;
		});
	}

	/* Locked buttons use aria-disabled, not disabled: a focused button that becomes disabled
	   drops keyboard focus to <body>, and with two counters sharing one seat limit that
	   happens on every click that reaches the limit (same choice as the day pager). */
	function setLocked(btn, locked) {
		btn.setAttribute('aria-disabled', locked ? 'true' : 'false');
	}

	function isLocked(btn) {
		return btn.getAttribute('aria-disabled') === 'true';
	}

	function buildPersonGroup(product, available, form, rows) {
		var limit = personLimit(product, available);
		var minTotal = personMinTotal(product);
		var cta = null;

		var group = el('div', 'wbc-grid__persons');
		group.setAttribute('role', 'group');
		group.setAttribute('aria-label', i18n('persons', 'People'));

		function sync() {
			var sum = personRowsTotal(rows);
			rows.forEach(function (row) {
				row.output.textContent = String(row.value);
				row.input.value = String(row.value);
				// Never below the type's minimum nor below the party minimum the cart enforces.
				setLocked(row.minus, row.value <= row.min || sum <= minTotal);
				setLocked(row.plus, row.value >= row.max || sum >= limit);
			});
			// A starting party the type maximums could not lift to the minimum is not sendable.
			// Never re-enable mid-request: the in-flight lock owns the CTA until it settles.
			if (cta) {
				cta.disabled = sum < minTotal || form.dataset.inflight === '1';
				cta.classList.toggle('wbc-grid__cta--blocked', sum < minTotal);
			}
		}

		rows.forEach(function (row) {
			var line = el('div', 'wbc-grid__persons-row');
			var label = el('span', 'wbc-grid__persons-label', typeLabel(row.type));
			line.appendChild(label);

			var stepper = el('div', 'wbc-grid__stepper');
			stepper.setAttribute('role', 'group');
			stepper.setAttribute('aria-label', row.type.label);

			row.minus = el('button', 'wbc-grid__step wbc-grid__step--minus', '−');
			row.minus.type = 'button';
			row.minus.setAttribute('aria-label', fillName(i18n('decreaseType', 'Fewer: %s'), row.type.label));

			row.output = el('span', 'wbc-grid__step-value', String(row.value));
			row.output.setAttribute('aria-live', 'polite');

			row.plus = el('button', 'wbc-grid__step wbc-grid__step--plus', '+');
			row.plus.type = 'button';
			row.plus.setAttribute('aria-label', fillName(i18n('increaseType', 'More: %s'), row.type.label));

			row.input = hidden(row.type.field, row.value);
			form.appendChild(row.input);

			row.minus.addEventListener('click', function () {
				if (!isLocked(row.minus)) {
					row.value--;
					sync();
				}
			});
			row.plus.addEventListener('click', function () {
				if (!isLocked(row.plus)) {
					row.value++;
					sync();
				}
			});

			stepper.appendChild(row.minus);
			stepper.appendChild(row.output);
			stepper.appendChild(row.plus);
			line.appendChild(stepper);
			group.appendChild(line);
		});

		sync();
		return {
			node: group,
			bindCta: function (button) {
				cta = button;
				sync();
			}
		};
	}

	// How many CONSECUTIVE hourly start slots (this one included) the product has from `slot`
	// onward in the selected day — each counted only when it still has seats. Mirrors the
	// vendor's hard boundary on the display side (class-wc-product-booking.php:2287: a block
	// whose end exceeds the continuous range never gets created; end == boundary is legal, so
	// slots at 14..18 give a reach of 5 → a 5 h option ending 19:00 stays). A sold-out slot IS
	// present in records (hide_unavailable=false) and must not extend the chain: a length that
	// spans it is guaranteed server rejection — exactly the display↔enforcement asymmetry this
	// clamp removes. Only for hour-unit step-1 products; anything else keeps the configured max
	// and the server stays the source of truth.
	function consecutiveHourReach(slot, product, dcMax) {
		var daySlots = buckets[selectedKey] || [];
		var startTimes = {};
		for (var i = 0; i < daySlots.length; i++) {
			var s = daySlots[i];
			if (String(s.product_id) !== String(product.id)) {
				continue;
			}
			if (toInt(s.available, 0) > 0) {
				startTimes[slotTime(s)] = true;
			}
		}

		var m = /^(\d{2}):(\d{2})$/.exec(slotTime(slot));
		if (!m) {
			return dcMax;
		}
		var startMinutes = parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
		var reach = 0;
		while (reach < dcMax) {
			var t = startMinutes + reach * 60;
			if (t >= 1440) {
				// Never chain across midnight: wrapping (% 24) would match the SAME day's
				// early-morning slot — hours in the past — and extend the chain through it.
				// Conservative; for overnight windows the server stays the source of truth.
				break;
			}
			var hh = String(Math.floor(t / 60));
			var mm = String(t % 60);
			if (hh.length < 2) {
				hh = '0' + hh;
			}
			if (mm.length < 2) {
				mm = '0' + mm;
			}
			if (!startTimes[hh + ':' + mm]) {
				break;
			}
			reach++;
		}
		return reach;
	}

	function buildDurationSelect(slot, product, target) {
		var dc = product.durationControl || {};
		var min = toInt(dc.min, 1);
		var max = toInt(dc.max, 6);
		var step = toInt(dc.step, 1);

		// Clamp only when one duration unit == one hour == slot spacing (block size 1). The
		// select's step is always 1 (it counts blocks); the real block size travels separately
		// as dc.block — a 2-hour-block config must skip the clamp entirely.
		if (dc.unit === 'hour' && step === 1 && toInt(dc.block, 1) === 1) {
			var clamped = Math.min(max, consecutiveHourReach(slot, product, max));
			if (clamped >= min) {
				max = clamped;
			}
			// else: pathological config (reach shorter than min_duration) — keep the NATIVE
			// range untouched instead of offering a fabricated min-only option; server decides.
		}

		var wrap = el('label', 'wbc-grid__control wbc-grid__control--duration');
		wrap.appendChild(el('span', 'wbc-grid__control-label', i18n('duration', 'Duration')));

		var select = document.createElement('select');
		select.className = 'wbc-grid__select';
		for (var v = min; v <= max; v += step) {
			var opt = document.createElement('option');
			opt.value = String(v);
			opt.textContent = v + ' ' + i18n('hourShort', 'h');
			select.appendChild(opt);
		}
		select.value = String(min);
		target.value = String(min);
		select.addEventListener('change', function () {
			target.value = select.value;
		});

		wrap.appendChild(select);
		return wrap;
	}

	/* ------------------------------------------------------------------------- modal */

	/* Inline SVG rather than a multiplication-sign glyph, whose weight and vertical alignment
	   varied with the theme font. `createElementNS` is MANDATORY here: `document.createElement('svg')`
	   produces an HTMLUnknownElement that renders nothing and throws nothing. */
	function closeIcon() {
		var NS = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(NS, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('fill', 'none');
		svg.setAttribute('stroke', 'currentColor');
		svg.setAttribute('stroke-width', '2');
		svg.setAttribute('stroke-linecap', 'round');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		['M18 6 6 18', 'M6 6 18 18'].forEach(function (d) {
			var path = document.createElementNS(NS, 'path');
			path.setAttribute('d', d);
			svg.appendChild(path);
		});
		return svg;
	}

	function ensureModal() {
		if (modal) {
			return modal;
		}
		var overlay = el('div', 'wbc-grid__modal');
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('aria-labelledby', 'wbc-grid-modal-title');
		overlay.hidden = true;

		var dialog = el('div', 'wbc-grid__modal-dialog');

		var close = el('button', 'wbc-grid__modal-close');
		close.type = 'button';
		close.setAttribute('aria-label', i18n('close', 'Close'));
		close.appendChild(closeIcon());
		close.addEventListener('click', closeModal);

		var title = el('h2', 'wbc-grid__modal-title');
		title.id = 'wbc-grid-modal-title';

		/* Image above the description: the product image (session) or the featured image
		   (host). One element, created once, shown only when the payload carries `image`. The
		   description still goes through textContent, so nothing from the editor becomes HTML. */
		var img = el('img', 'wbc-grid__modal-img');
		img.hidden = true;
		img.alt = '';
		img.decoding = 'async';
		// A file deleted from disk while its library entry lives on: the URL exists, the image does
		// not. Rather than an empty frame with a broken-image icon, show no image at all.
		img.addEventListener('error', function () {
			img.hidden = true;
		});

		var desc = el('p', 'wbc-grid__modal-desc');

		/* The header does not scroll with the description, so the close button stays reachable.
		   DOM order is title then button, which makes a screen reader announce the dialog name
		   before the control. `close` remains the ONLY focusable element in the dialog, so the
		   Tab trap comparing `activeElement` against first and last keeps working unchanged. */
		var header = el('div', 'wbc-grid__modal-header');
		header.appendChild(title);
		header.appendChild(close);

		var body = el('div', 'wbc-grid__modal-body');
		body.appendChild(img);
		body.appendChild(desc);

		dialog.appendChild(header);
		dialog.appendChild(body);
		overlay.appendChild(dialog);

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				closeModal();
			}
		});
		overlay.addEventListener('keydown', onModalKey);

		roots.grid.appendChild(overlay);

		modal = {
			overlay: overlay,
			dialog: dialog,
			body: body,
			title: title,
			img: img,
			desc: desc
		};
		return modal;
	}

	function openModal(product, trigger) {
		var m = ensureModal();
		lastTrigger = trigger || null;

		m.title.textContent = product.label;
		m.desc.textContent = product.description || '';

		// Drop the previous image first: otherwise the old bitmap lingers under the new title until
		// the new file decodes (decoding=async).
		m.img.removeAttribute('src');
		m.img.removeAttribute('srcset');
		m.img.removeAttribute('sizes');
		var image = product.image && product.image.src ? product.image : null;
		if (image) {
			// width/height BEFORE src: the browser reserves space from the ratio, so the description
			// does not jump down when the image arrives.
			if (image.width > 0 && image.height > 0) {
				m.img.width = image.width;
				m.img.height = image.height;
			} else {
				m.img.removeAttribute('width');
				m.img.removeAttribute('height');
			}
			m.img.alt = image.alt || '';
			if (image.srcset) {
				m.img.srcset = image.srcset;
				m.img.sizes = '(max-width: 552px) calc(100vw - 80px), 472px';
			}
			m.img.src = image.src;
			m.img.hidden = false;
		} else {
			m.img.hidden = true;
		}

		m.overlay.hidden = false;
		// The previous dialog may have been scrolled; the new one opens at the top. AFTER unhiding:
		// an element without a box (display:none) ignores a scrollTop write.
		m.body.scrollTop = 0;
		document.body.classList.add('wbc-grid-modal-open');
		var focusable = getFocusable(m.dialog);
		if (focusable.length) {
			focusable[0].focus();
		}
	}

	function closeModal() {
		if (!modal || modal.overlay.hidden) {
			return;
		}
		modal.overlay.hidden = true;
		document.body.classList.remove('wbc-grid-modal-open');

		if (pendingApply) {
			// Deferred post-add render: apply first, then return focus to the RE-RENDERED
			// equivalent of the trigger (the original node is detached by the render).
			var apply = pendingApply;
			pendingApply = null;
			var card = lastTrigger && lastTrigger.closest ? lastTrigger.closest('.wbc-grid__card') : null;
			var slotIsoId = card ? card.dataset.slotIso : null;
			var productId = card ? card.dataset.productId : null;
			lastTrigger = null;
			apply(true);
			var eq = null;
			if (slotIsoId && productId) {
				eq = roots.list.querySelector(
					'[data-slot-iso="' + cssEscape(slotIsoId) + '"][data-product-id="' + cssEscape(productId) + '"] .wbc-grid__name'
				);
			}
			if (eq) {
				eq.focus();
			} else if (roots.toast) {
				roots.toast.focus();
			}
			return;
		}

		if (lastTrigger && typeof lastTrigger.focus === 'function') {
			lastTrigger.focus();
		}
		lastTrigger = null;
	}

	function onModalKey(e) {
		if (e.key === 'Escape') {
			e.preventDefault();
			// Stop here. An accessibility widget on the page may also listen for Escape on
			// the document and pull focus onto its own toggle — which lands AFTER closeModal()
			// has correctly returned focus to the trigger, silently undoing it. A modal that
			// consumes Escape must not let it reach global handlers.
			e.stopPropagation();
			closeModal();
			return;
		}
		if (e.key !== 'Tab') {
			return;
		}
		var focusable = getFocusable(modal.dialog);
		if (!focusable.length) {
			return;
		}
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	}

	function getFocusable(container) {
		var nodes = container.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
		var out = [];
		for (var i = 0; i < nodes.length; i++) {
			if (!nodes[i].disabled && nodes[i].offsetParent !== null) {
				out.push(nodes[i]);
			}
		}
		return out;
	}

	/* -------------------------------------------------------------------------- init */

	function init() {
		var grid = document.getElementById('wbc-grid');
		if (!grid) {
			return;
		}
		roots.grid = grid;
		roots.picker = grid.querySelector('.wbc-grid__daypicker');
		roots.list = grid.querySelector('.wbc-grid__list');
		// Server-rendered, visually hidden role=status region — in the DOM from the first
		// paint (a live region injected at announce time is ignored by some AT).
		roots.status = grid.querySelector('.wbc-grid__status');
		if (!roots.picker || !roots.list) {
			return;
		}

		// Toast region OUTSIDE the list (sibling): renderList() wipes list.textContent, so a
		// toast inside the list would vanish the instant it appears and a screen reader would
		// never announce it. The live region exists from init on, before any message.
		roots.toast = el('div', 'wbc-grid__toast-region');
		roots.toast.setAttribute('role', 'status');
		roots.toast.setAttribute('aria-live', 'polite');
		// Programmatic focus target for the rare case the booked slot vanishes on rerender.
		roots.toast.setAttribute('tabindex', '-1');
		roots.grid.appendChild(roots.toast);

		initNav();

		if (nav) {
			// Initial load IS the first navigation: page 0 through the same epoch-gated path,
			// so a slow cold init can never land over a page the visitor already moved to.
			showPage(0);
			return;
		}

		// Legacy mode (payload without the navigation lexicon): the server-built 8-day window,
		// same request as always — but a failure now says so instead of posing as an empty week.
		renderDayPicker();
		var epoch = ++slotsEpoch;
		fetchSlots(CFG.minDate, CFG.maxDate).then(function (res) {
			if (epoch !== slotsEpoch) {
				return;
			}
			if (!res.ok) {
				renderListError();
				return;
			}
			buckets = bucketize(res.records);
			renderList();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
