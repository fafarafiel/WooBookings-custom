# WooBookings Custom

A companion plugin for WooCommerce Bookings. It replaces the vendor booking form with a day strip
and a list of slots, adds special events that own the whole evening, assigns a host per recurring
slot, and repairs three vendor behaviours that break on a multilingual shop.

It touches no vendor file. Every behaviour is added through documented hooks, so the vendor plugin
can be updated without re-applying anything.

```
[ ‹ ]  MON 10   TUE 11   WED 12   THU 13   FRI 14   SAT 15  [ › ]     MONTH [ August ▾ ]

  16:00   Forest Ritual                     2 h    16/16 seats   [ − 1 + ]   [ Book ]
          Host: A. Example

  18:15   Northern Lights                   2 h    16/16 seats   [ − 1 + ]   [ Book ]
          Host: B. Example
```

## Why it exists

WooCommerce Bookings is solid at availability arithmetic and weak at presentation. Its native form
asks a visitor to pick a date, then discover what is available, then pick a time. A venue running
several session types a day needs the opposite: show me this week, tell me what is on, let me book
in one click.

Three further problems have no native answer at all:

| Problem | What actually happens | What this plugin does |
| --- | --- | --- |
| Multilingual bookings | WooCommerce Multilingual mirrors every new booking onto each translated product. A booking is a transaction, not translatable content, and the copies corrupt availability on a shared resource. | Removes the duplication callback at the right priority, so a booking stays one entity. |
| Cache asymmetry | Adding a booking clears the vendor's availability transient. Deleting, cancelling or expiring one does not. Seats stay invisible for up to an hour. | Flushes explicitly on every downward path, including the cron that expires an abandoned cart. |
| Whole-evening exclusivity | Nothing native says "while this runs, nothing else can be booked on the same resource". | A tier hierarchy (event beats session beats open entry) expressed as availability rules, so it reaches both the grid and the checkout validator. |

## What it does

- **Booking grid** rendered from a shortcode. Day strip, paging by whole pages of days, month jump,
  add to cart without leaving the page.
- **Special events** with their own seat cap and exclusivity over the shared resource.
- **Host per slot**. One shared registry of people; a default per product plus overrides for
  individual recurring slots. The assignment screen is generated from the product's own schedule,
  so nobody types a date.
- **Hold window** shortened from the vendor default, with the number quoted to the customer coming
  from the same constant that drives the real expiry.
- **Configuration validators** that refuse to fail silently. A capacity written on a translation,
  an event with no price, an event whose capacity exceeds the resource, a session with no bookable
  rule: each raises a specific admin notice naming the product.

## Requirements

- WordPress 6.4 or newer
- PHP 8.2 or newer
- WooCommerce and WooCommerce Bookings, active
- WPML and WooCommerce Multilingual, optional. The plugin behaves correctly with or without them.

## Install

Copy `plugin/` into `wp-content/plugins/woobookings-custom/` and activate it. There is no build
step, no bundler and no dependency to install: the front end is one CSS file and two vanilla ES5
scripts.

Then set the anchor resource under **Settings, WooBookings Custom**, and drop the shortcode on a
page:

```
[wbc_grid]
```

Every bookable product attached to that resource is discovered automatically. Nothing is hard
coded, and a product ID never appears in the source.

## How it fits together

```
  shortcode  ──▶  WBC_Grid_Data  ──▶  payload (products, days, lexicon, hosts, i18n)
                        │                        │
                        │                        ▼
                        │                  grid.js  ──fetch──▶  wc-bookings/v1/products/slots
                        │                        │
                        ▼                        ▼
                  WBC_Config              add to cart (native Woo form post)
                  (resource ──▶ products)
                        ▲
                        │
   WBC_Events ──────────┘   capacity cap + exclusivity, via three vendor filters
   WBC_WPML_Guard           duplication removed at plugins_loaded priority 20
   WBC_Cache_Flush          explicit flush on trash, cancel, refund, hold expiry, config save
```

Longer version with the reasoning behind each boundary: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Tests

```bash
php tests/php/run.php            # 20 assertions, no WordPress needed
node --test tests/js/slots.test.js   # 10 tests, no DOM needed
```

Both suites are dependency free and run in under a second, which is deliberate. The logic worth
testing here is arithmetic, not integration: month arithmetic that overflows, weekday numbering
that differs between JavaScript and ISO, a parser that has to ignore four rule shapes and keep one.
Those are exactly the failures that produce a wrong booking rather than a crash.

CI additionally lints every PHP file across three PHP versions, refuses debug statements in shipped
assets, checks that every file guards direct access, and fails the build if the plugin header
version drifts from the constant.

## Notable implementation notes

Collected because each one cost real debugging time. Full list in
[`docs/GOTCHAS.md`](docs/GOTCHAS.md).

- **Specificity is load bearing.** Themes paint every button on hover **and** focus from one
  selector. Covering only `:hover` leaves a foreign colour on screen after a mouse click, because a
  click leaves focus behind and `:focus-visible` does not match it. Source order breaks ties, so
  `:focus` must precede `:hover`.
- **A state must never change geometry.** A hover rule that adds padding grows the element and the
  list jumps under the cursor. Colour and outline are safe; padding, border and margin are not.
- **`document.createElement('svg')` returns an HTMLUnknownElement.** It renders nothing and throws
  nothing. Inline SVG needs `createElementNS`.
- **Weekday keys follow ISO, JavaScript does not.** `getUTCDay()` calls Sunday 0 while the map keys
  call it 7. The weekday is derived with UTC arithmetic over naive ISO parts so a daylight saving
  transition cannot move a slot to the wrong day.
- **Availability keys on the canonical product.** A value written on a translation is invisible to
  the runtime. That is a silent failure, so a validator shouts instead of letting it degrade.

## Licence

GPL-2.0-or-later, matching WordPress and the vendor plugin it extends.
