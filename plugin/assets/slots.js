/**
 * Pure slot helpers, shared by the grid and covered by the test suite.
 *
 * These four functions carry the date logic that is easiest to get wrong and hardest to notice
 * when it is wrong: an off-by-one weekday moves every override onto a key nothing reads, and a
 * timezone slip moves a slot to the wrong day around a daylight saving transition. They live in
 * their own file so the tests can require them directly, with no DOM, no build step and no
 * evaluating of source at run time.
 *
 * The file is deliberately dependency-free and attaches to `window` in a browser while exporting
 * under CommonJS in Node, so the same code runs in both without a bundler.
 *
 * @package WooBookings_Custom
 */

(function (root, factory) {
	var api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else {
		root.WBCSlots = api;
	}
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	/**
	 * Parse an integer with a fallback, so a malformed field degrades instead of yielding NaN.
	 *
	 * @param {*} value Raw value.
	 * @param {number} fallback Value to use when parsing fails.
	 * @returns {number}
	 */
	function toInt(value, fallback) {
		var n = parseInt(value, 10);
		return isNaN(n) ? fallback : n;
	}

	/**
	 * Format an epoch in milliseconds into date parts for a given IANA zone, independent of the
	 * browser's own timezone.
	 *
	 * @param {Date} dateObj Date to format.
	 * @param {string} timeZone IANA timezone name.
	 * @returns {Object} Parts keyed year, month, day, hour, minute, second.
	 */
	function zonedParts(dateObj, timeZone) {
		var fmt = new Intl.DateTimeFormat('en-CA', {
			timeZone: timeZone,
			year: 'numeric', month: '2-digit', day: '2-digit',
			hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
		});
		var parts = fmt.formatToParts(dateObj);
		var out = {};
		for (var i = 0; i < parts.length; i++) {
			out[parts[i].type] = parts[i].value;
		}
		// Some engines render midnight as hour 24 rather than 00.
		if (out.hour === '24') {
			out.hour = '00';
		}
		return out;
	}

	/**
	 * Normalise a slot date to a naive local ISO string.
	 *
	 * The endpoint returns either a naive local ISO string, which is the expected shape, or a
	 * numeric timestamp as a fallback. A raw string is never passed through `new Date()`: that
	 * would reinterpret shop-local wall time in the browser's timezone and move slots between
	 * days for anybody travelling.
	 *
	 * @param {Object} slot Slot record.
	 * @param {string} timeZone Shop timezone, used only for the numeric fallback.
	 * @returns {string} ISO string, or an empty string when the input is unusable.
	 */
	function slotIso(slot, timeZone) {
		var d = slot && slot.date;
		if (typeof d === 'string') {
			return d.indexOf('T') === -1 ? d.replace(' ', 'T') : d;
		}
		if (typeof d === 'number') {
			var ms = d < 1e12 ? d * 1000 : d;
			var p = zonedParts(new Date(ms), timeZone || 'UTC');
			return p.year + '-' + p.month + '-' + p.day + 'T' + p.hour + ':' + p.minute + ':' + p.second;
		}
		return '';
	}

	/**
	 * Map key for a slot: `ISOWeekday|HH:MM`, for example `6|18:15`.
	 *
	 * Keyed by weekday rather than date because a schedule is recurring with no end date, so a
	 * date-keyed map would grow without bound and need topping up every week.
	 *
	 * The weekday comes from UTC arithmetic over the naive ISO parts rather than
	 * `new Date(string)`, so a daylight saving transition cannot shift it. JavaScript numbers
	 * Sunday as 0 while the keys follow ISO, where Sunday is 7.
	 *
	 * @param {string} iso Naive local ISO string.
	 * @returns {string} Key, or an empty string when the input is unusable.
	 */
	function slotKey(iso) {
		if (!iso || iso.length < 16) {
			return '';
		}
		var y = toInt(iso.slice(0, 4), 0);
		var m = toInt(iso.slice(5, 7), 0);
		var d = toInt(iso.slice(8, 10), 0);
		if (!y || !m || !d) {
			return '';
		}
		var dow = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
		return (dow === 0 ? 7 : dow) + '|' + iso.slice(11, 16);
	}

	/**
	 * Resolve the host for one slot: the per-slot override when present, otherwise the product
	 * default, otherwise nobody.
	 *
	 * Returning null rather than a placeholder is what keeps the card free of an empty row: the
	 * renderer draws the line on PRESENCE OF DATA, never on product type.
	 *
	 * @param {Object} product Product descriptor from the payload.
	 * @param {Object} slot Slot record.
	 * @param {string} [timeZone] Shop timezone, used only for the numeric date fallback.
	 * @returns {Object|null} Host with name and bio, or null.
	 */
	function hostForSlot(product, slot, timeZone) {
		if (!product) {
			return null;
		}
		var key = slotKey(slotIso(slot, timeZone));
		if (key && product.hosts && product.hosts[key]) {
			return product.hosts[key];
		}
		return product.hostDefault || null;
	}

	return {
		toInt: toInt,
		zonedParts: zonedParts,
		slotIso: slotIso,
		slotKey: slotKey,
		hostForSlot: hostForSlot
	};
}));
