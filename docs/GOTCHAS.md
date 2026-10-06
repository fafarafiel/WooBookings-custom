# Gotchas

Behaviours that cost real debugging time, written down so the next person does not pay twice. Each
one was measured, not assumed.

## CSS and themes

**A theme paints hover and focus from one selector.** The common reset uses a single rule of the
shape `[type="button"]:focus, [type="button"]:hover, button:focus, button:hover`. Cover only
`:hover` and a mouse click leaves the theme colour on screen permanently, because a click leaves
focus behind and `:focus-visible` does not match pointer input. The same mistake happened three
times in this component before it was written down.

**Order breaks specificity ties.** `:focus`, `:hover` and `:focus-visible` all sit at the same
specificity once prefixed. Source order decides, so `:focus` must come first or hover dies on any
control that happens to be focused. Which, for a modal close button, is always.

**Check the base rule, not only the state rule.** A control whose base already carries a prefix
ties the theme and wins on sheet order, so it defends itself. A bare class loses and needs an
explicit `:focus`. That is why two controls in the same component behaved differently.

**A state must never change geometry.** Hover that adds padding grows the element, and the list
jumps under the cursor. A negative margin compensating the horizontal axis does not compensate the
vertical one. Colour, background and outline are safe.

**A same-specificity media block placed before the base rule loses.** Media queries add no weight.
Mobile overrides belong at the end of the file.

## JavaScript

**`document.createElement('svg')` produces an HTMLUnknownElement.** It renders nothing and throws
nothing, so the icon is simply absent with a clean console. Inline SVG requires `createElementNS`.

**Sunday is 0 in JavaScript and 7 in ISO.** A map keyed by ISO weekday and read with `getUTCDay()`
sends every Sunday override to a key nothing reads.

**Never pass a naive date string through `new Date()`.** It reinterprets shop-local wall time in the
browser timezone, which moves slots across days for anyone travelling. Parse the parts and do the
arithmetic in UTC.

**A focus trap that compares identity breaks when you move focus.** The trap here compares
`activeElement` against the first and last focusable element. Moving initial focus to the dialog
itself with `tabindex="-1"` removes it from that list, and the first Shift+Tab escapes the modal.

**A sticky element under a fixed header can hide a focused control.** The browser does not scroll
it into view, because the element is inside the viewport and merely covered. That is a WCAG 2.2
SC 2.4.11 failure. Revealing it on `:has(...:focus-visible)` is free, because an obscured control
cannot be clicked in the first place.

## WordPress and WooCommerce Bookings

**Availability caching has three gaps on a shared resource.** Creating, cancelling, refunding,
trashing and restoring a booking clear every product on the resource. Cron expiry of an abandoned cart and a
permanent delete of a booking that was never trashed clear at most the booking's own product, and
a product save clears only that product.

**The cart expiry cron bypasses the data store.** It calls `wp_delete_post()` directly, so the
vendor's own delete action never fires. The post type is not routed to the trash either, so
`trashed_post` is silent. The hook that catches it has to fire before the row is removed
(`before_delete_post` here, or core's `delete_post`): after deletion `get_post_type()` returns
false and a type guard rejects the call.

**The displayed seat count does not pass through `get_available_quantity`.** Display and enforcement
are separate paths. Cap one and the grid disagrees with the checkout.

**`WC_Bookings_Install::install()` is private.** An `is_callable()` guard on it is always false, so
a migration nudge written that way never runs. The public entry points are `maybe_install()` and
`maybe_update()`.

**The bookings REST list endpoint under-reports.** Build availability from the slots endpoint
instead. The products endpoint writes availability, pricing and person settings, but not person types or
resources.

**Multilingual duplication is unconditional.** Marking a product non-translatable does not stop it.
Removing the callback does, and the removal has to run after the other plugin has registered it.

## Testing

**A cached endpoint cannot be its own control.** Probing a one-hour transient before and after a
deploy returns the same numbers whatever happened in between. Use something with no cache in the
path, such as the rendered payload, or a negative control that changes a different variable.

**A parked cursor produces false hover readings.** Move the pointer away before measuring computed
styles, or every element under the last click looks hovered.

**"The behaviour is right" is not "my code did it."** Confirm the winning rule or the firing hook
directly. Twice in this codebase a fix looked verified while the code path could not execute at
all, and both times the evidence was behavioural rather than attributive.
