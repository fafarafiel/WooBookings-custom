# Architecture

## The one constraint that shapes everything

The vendor plugin owns availability. It knows about resources, capacity, buffers, timezones,
daylight saving and the race between two people buying the last seat. Reimplementing any of that
would be a decade of bugs for no gain.

So this plugin never computes availability. It reads it, presents it, and constrains it through
documented filters. Every design decision below follows from that.

## Layers

### Configuration: one anchor, everything else discovered

`WBC_Config` resolves a single shared resource ID from options and then finds every bookable
product attached to it. Nothing else is configured, and no product ID appears anywhere in the code.

The alternative, a list of product IDs in an option, was rejected. Editors add and remove products;
a list goes stale silently and the grid quietly stops showing a session nobody notices is missing.
Discovery from the resource cannot go stale, because the resource is what makes a product part of
this system in the first place.

Discovery is language agnostic on purpose: it queries with filters suppressed, so a translated
admin screen does not shrink the catalogue.

### Data: one payload, built server side

`WBC_Grid_Data` builds a single payload consumed through `wp_localize_script`. It carries products,
the initial page of days, the whole month and weekday lexicon, host assignments and every UI string.

The lexicon is server rendered rather than produced with `Intl` in the browser. Month and weekday
names must match the rest of the site, which means going through the site's own localisation stack
rather than a second one with slightly different spelling rules. `Intl` is used in the browser only
for arithmetic, never to render text.

### Availability axis and presentation axis

This is the seam that multilingual support lives or dies on.

- The **availability axis** always keys on the canonical product. Capacity, host assignments and
  event markers are read there and nowhere else.
- The **presentation axis** reads label, description, permalink and add-to-cart target from the
  translated product for the current language.

Mixing them is the classic failure: a value written on a translation passes validation, then does
nothing at run time because the runtime looks at the canonical product. That is a silent failure,
so `WBC_Settings` validates on the same canonicalisation the runtime uses and raises a specific
notice when the two disagree.

### Front end: no build step

One CSS file and two scripts, plain ES5, no bundler. On a WordPress host there is no toolchain,
and a build artifact nobody can rebuild on the server is a liability rather than an asset.

`assets/slots.js` holds the pure date logic and is the only file the test suite imports.
`assets/grid.js` delegates to it, which keeps one source of truth for the arithmetic and lets the
tests run in Node with no DOM and no evaluation of source at run time.

### Events and exclusivity

An event is an ordinary bookable product carrying a capacity meta. Three vendor filters give it its
behaviour:

1. `get_available_quantity` for enforcement, because that is what the form validator and the
   bookability check consult.
2. `filter_time_slots` for display, because the number shown to a visitor does **not** pass through
   `get_available_quantity`. Capping only one of the two produces a grid that advertises seats the
   checkout then refuses, or the reverse.
3. `woocommerce_booking_get_availability_rules` for exclusivity. Expressing "nothing else runs
   while this runs" as availability rules means it reaches the grid and the checkout through the
   same path, instead of needing a second implementation for each.

Tiers are integers, so precedence is a comparison rather than a chain of conditionals: event beats
session beats open entry.

### Cache

The vendor caches slot availability per product for an hour, keyed on the query parameters. It
clears that cache when a booking is created, and not when one is deleted, cancelled, refunded or
expired by cron. The asymmetry is invisible in testing, where you usually add rather than remove.

`WBC_Cache_Flush` hooks the downward paths explicitly. One of them is only reachable through
`before_delete_post`: the cron that expires an abandoned cart calls `wp_delete_post()` directly,
which bypasses the data store, so the vendor's own delete action never fires, and the post type is
not one WordPress routes to the trash.

Configuration saves flush too. Changing a session's hours changes availability for every product on
the shared resource, not only the edited one, and the vendor clears the transient of the edited
product alone.

## What is deliberately not here

- **No custom checkout.** Add to cart uses the native form post, so payment, taxes, coupons and
  order emails stay entirely the vendor's problem.
- **No writes through the REST API.** The bookings REST endpoint is documented as incomplete for
  creation, and the products endpoint silently ignores availability and person fields on write.
- **No price logic.** `WBC_Cost` exists as the single place a price rule would live, and registers
  nothing, because the vendor's native per-person multiplier already produces the right number.
  An empty pass-through on a hot filter is cost without behaviour.
- **No caching of our own.** A second cache layer on top of a vendor cache with a different
  invalidation model is how stale seats happen.
