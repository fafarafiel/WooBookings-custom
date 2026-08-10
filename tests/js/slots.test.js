/**
 * Tests for the pure slot logic.
 *
 * The helpers live in their own dependency-free module (plugin/assets/slots.js) precisely so the
 * suite can require the shipped file directly: no DOM, no build step, and no evaluating source at
 * run time. The grid delegates to this module, so a passing suite covers the code that ships.
 *
 * Run: node --test tests/js
 */

const test = require('node:test');
const assert = require('node:assert');
const sandbox = require('../../plugin/assets/slots.js');

const ANNA = { name: 'A. Example', bio: 'bio a' };
const PIOTR = { name: 'B. Example', bio: 'bio b' };

test('slotIso normalises the two shapes the endpoint returns', () => {
  assert.strictEqual(sandbox.slotIso({ date: '2026-08-15T18:15:00' }), '2026-08-15T18:15:00');
  assert.strictEqual(sandbox.slotIso({ date: '2026-08-15 18:15:00' }), '2026-08-15T18:15:00');
  assert.strictEqual(sandbox.slotIso({ date: null }), '');
});

test('a slot with no host data shows nobody', () => {
  const product = { hosts: {}, hostDefault: null };
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-08-15T16:00:00' }), null);
});

test('the product default applies to every slot', () => {
  const product = { hosts: {}, hostDefault: ANNA };
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-08-13T16:00:00' }), ANNA);
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-08-15T16:00:00' }), ANNA);
});

test('a per-slot override wins over the default, and only on its own slot', () => {
  // Saturday 18:15 is overridden; every other slot of the same product keeps the default.
  const product = { hosts: { '6|18:15': PIOTR }, hostDefault: ANNA };
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-08-15T18:15:00' }), PIOTR, 'Saturday 18:15');
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-08-15T16:00:00' }), ANNA, 'same day, different hour');
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-08-11T18:15:00' }), ANNA, 'same hour, different day');
});

test('an override with no default shows the host only on the overridden slot', () => {
  const product = { hosts: { '6|18:15': PIOTR }, hostDefault: null };
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-08-15T18:15:00' }), PIOTR);
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-08-12T16:00:00' }), null);
});

test('Sunday maps to ISO 7, not 0', () => {
  // JavaScript numbers Sunday as 0; the map keys follow ISO, where Sunday is 7. Getting this
  // wrong would silently move every Sunday override onto a key nothing ever reads.
  const product = { hosts: { '7|12:00': PIOTR }, hostDefault: ANNA };
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-08-16T12:00:00' }), PIOTR);
});

test('every weekday maps to its ISO number', () => {
  // 2026-08-10 is a Monday, so the week runs Monday to Sunday from that date.
  const expected = [1, 2, 3, 4, 5, 6, 7];
  expected.forEach((iso, offset) => {
    const day = String(10 + offset).padStart(2, '0');
    const product = { hosts: {}, hostDefault: null };
    product.hosts[iso + '|10:00'] = PIOTR;
    assert.strictEqual(
      sandbox.hostForSlot(product, { date: '2026-08-' + day + 'T10:00:00' }),
      PIOTR,
      'offset ' + offset + ' should map to ISO ' + iso
    );
  });
});

test('the weekday survives a daylight saving transition', () => {
  // Europe switches on the last Sunday of October. Computing the weekday with local-time
  // arithmetic can slip by a day around the transition; the source uses UTC arithmetic over the
  // naive ISO parts precisely so it cannot.
  const product = { hosts: { '7|12:00': PIOTR }, hostDefault: null };
  assert.strictEqual(sandbox.hostForSlot(product, { date: '2026-10-25T12:00:00' }), PIOTR, 'DST end, a Sunday');

  const spring = { hosts: { '7|12:00': PIOTR }, hostDefault: null };
  assert.strictEqual(sandbox.hostForSlot(spring, { date: '2027-03-28T12:00:00' }), PIOTR, 'DST start, a Sunday');
});

test('a malformed date falls back to the default rather than throwing', () => {
  const product = { hosts: { '6|18:15': PIOTR }, hostDefault: ANNA };
  assert.strictEqual(sandbox.hostForSlot(product, { date: 'garbage' }), ANNA);
  assert.strictEqual(sandbox.hostForSlot(product, { date: null }), ANNA);
});

test('a product with no hosts map at all is safe', () => {
  assert.strictEqual(sandbox.hostForSlot({ hostDefault: ANNA }, { date: '2026-08-15T18:15:00' }), ANNA);
  assert.strictEqual(sandbox.hostForSlot({}, { date: '2026-08-15T18:15:00' }), null);
});
