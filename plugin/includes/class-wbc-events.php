<?php
/**
 * Events. Special-event runtime and the exclusivity hierarchy over the shared resource pool.
 *
 * Two mechanisms:
 *   1. own seat capacity for events (e.g. 10 on the 20-seat resource), driven by the
 *      event-capacity meta (WBC_Config::META_EVENT_CAPACITY) read on the CANONICAL product;
 *   2. the exclusivity hierarchy event > session > open (tiers 3 > 2 > 1): a product's slots
 *      are blacked out by the bookable windows of every product with a HIGHER tier. The event
 *      keeps its whole-evening exclusivity as the top tier; a regular session replaces the open
 *      entry in its own slot as the middle one. Since
 *      v1.1.0 the top tier comes from the explicit marker (WBC_Config::META_IS_EVENT), not
 *      from the capacity meta: regular sessions may carry a capacity too, so capacity alone
 *      no longer says "event".
 *
 * Three vendor filters, verified against the unpacked Bookings 3.7.0 source, NOT guessed
 * Each callback canonicalizes the passed product FIRST: on a translated checkout the
 * vendor passes a translation whose id carries no event meta, so reading the raw id would
 * make cap and blackout a silent no-op in other languages.
 *
 *   1. woocommerce_bookings_get_available_quantity (class-wc-product-booking.php:1413,
 *      args: $available_qty, $product, $booking_resource) — cap enforcement. Its return
 *      feeds the booking form's free-slot display (class-wc-booking-form.php:782) and block
 *      bookability (class-wc-product-booking.php:1890,2214).
 *   2. woocommerce_bookings_filter_time_slots (wc-bookings-functions.php:1144,
 *      args: $slots, $bookable_product, $args) — cap on the count the grid DISPLAYS. The
 *      shown `available` is computed from the raw pool qty (:1104), not get_available_quantity,
 *      so the grid needs its own cap here. Runs AFTER WC_Bookings_Cache::set (:1110) → cache-safe.
 *   3. woocommerce_booking_get_availability_rules (class-wc-product-booking.php:1569,
 *      args: $rules, $for_resource, $product) — tier blackout. Sits ABOVE the display↔enforcement
 *      split: both the slots endpoint and the
 *      cart validation grow out of get_bookable_minute_blocks_for_date, so one filter covers both.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the three runtime filters. Front + REST (booking availability is computed in both),
 * so this is wired unconditionally, unlike the admin-only settings/event-field classes.
 */
final class WBC_Events {

	/** @var WBC_Config */
	private $config;

	/** @var WBC_WPML_Guard */
	private $wpml_guard;

	/**
	 * @var array<int,array> Processed bookable=no rules keyed by the REQUESTING product's tier;
	 * per-request memo. Per-tier and never shared: a single merged set built first for the
	 * ceremony (tier 2 → event windows only) and then handed to the open product (tier 1, which
	 * must also get the ceremony windows) would silently lose the ceremony exclusivity —
	 * memo poisoning.
	 */
	private $blackout_rules = array();

	/**
	 * @param WBC_Config     $config
	 * @param WBC_WPML_Guard $wpml_guard
	 */
	public function __construct( WBC_Config $config, WBC_WPML_Guard $wpml_guard ) {
		$this->config     = $config;
		$this->wpml_guard = $wpml_guard;

		add_filter( 'woocommerce_bookings_get_available_quantity', array( $this, 'cap_available_quantity' ), 10, 3 );
		add_filter( 'woocommerce_bookings_filter_time_slots', array( $this, 'cap_time_slots' ), 10, 3 );
		add_filter( 'woocommerce_booking_get_availability_rules', array( $this, 'blackout_for_events' ), 10, 3 );
	}

	/**
	 * Filter 1 — cap the raw available quantity to the event capacity. $available_qty is the pool
	 * qty BEFORE bookings are subtracted (class-wc-product-booking.php:1411-1412), so min(cap, qty)
	 * caps the event to its own limit while the shared pool keeps working (bookings subtract after).
	 *
	 * @param int         $available_qty    Raw resource/product qty.
	 * @param object      $product          WC_Product_Booking (arg 2).
	 * @param object|null $booking_resource Resource or null (unused).
	 * @return int
	 */
	public function cap_available_quantity( $available_qty, $product, $booking_resource = null ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $available_qty;
		}
		$canon = $this->wpml_guard->wbc_canonical_id( $product->get_id() );
		$cap   = $this->config->get_event_capacity( $canon );
		if ( $cap <= 0 ) {
			return $available_qty;
		}
		return (int) min( (int) $available_qty, $cap );
	}

	/**
	 * Filter 2 — cap the displayed `available` per slot for an event. The grid derives the "N/Y"
	 * capacity from available+booked (grid.js:273-301), so clamping `available` to cap-booked shows
	 * N/cap with no front-end change. Skips the non-slot 'old_availability' flag (:1133).
	 *
	 * @param array  $slots            block => ['booked','available','resources'] (+ 'old_availability').
	 * @param object $bookable_product WC_Product_Booking (arg 2).
	 * @param array  $args             Query args (unused).
	 * @return array
	 */
	public function cap_time_slots( $slots, $bookable_product, $args = array() ) {
		if ( ! is_array( $slots ) || ! is_object( $bookable_product ) || ! method_exists( $bookable_product, 'get_id' ) ) {
			return $slots;
		}
		$canon = $this->wpml_guard->wbc_canonical_id( $bookable_product->get_id() );
		$cap   = $this->config->get_event_capacity( $canon );
		if ( $cap <= 0 ) {
			return $slots;
		}

		foreach ( $slots as $block => $slot ) {
			if ( ! is_array( $slot ) || ! isset( $slot['available'], $slot['booked'] ) ) {
				continue; // 'old_availability' and any other non-slot flag.
			}
			$slots[ $block ]['available'] = max( 0, min( (int) $slot['available'], $cap - (int) $slot['booked'] ) );
		}

		return $slots;
	}

	/**
	 * Filter 3 — tier blackout (event > session > open). For a product on the anchor resource,
	 * append bookable=no rules covering every bookable range of every product with a HIGHER tier,
	 * at the END of the already-sorted rules array so they carry the highest override power. The
	 * event (tier 3) comes back unchanged — nothing sits above it, so its blackout set is empty,
	 * which preserves the pre-hierarchy behaviour exactly (no self-blackout, no recursion). A
	 * cyclic event series is covered because the ranges are transformed at the rule level (the
	 * same rrule/custom entries), not by expanding dates, so every occurrence in the window
	 * inherits the blackout.
	 *
	 * @param array  $rules        Processed availability rules (already usort-ed).
	 * @param int    $for_resource Resource ID (0 = none chosen; unused — blackout is resource-independent).
	 * @param object $product      WC_Product_Booking (arg 3).
	 * @return array
	 */
	public function blackout_for_events( $rules, $for_resource, $product ) {
		if ( ! is_array( $rules ) || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $rules;
		}
		$canon = $this->wpml_guard->wbc_canonical_id( $product->get_id() );

		// Only products actually on the anchor pool are subject to exclusivity.
		if ( ! in_array( $canon, $this->config->get_product_ids(), true ) ) {
			return $rules;
		}

		$blackout = $this->get_blackout_rules_above( $this->tier( $canon ) );
		if ( empty( $blackout ) ) {
			return $rules;
		}

		return array_merge( $rules, $blackout );
	}

	/**
	 * Exclusivity tier of an anchor product: event = 3, ceremony = 2, open/flex = 1. A product is
	 * blacked out by everything with a tier strictly above its own, so the hierarchy
	 * event > session > open entry falls out of a plain integer comparison.
	 *
	 * @param int $canon Canonical product ID.
	 * @return int
	 */
	private function tier( $canon ) {
		/*
		 * By the MARKER, not by capacity: regular sessions may carry a capacity, so `is_event()`
		 * would put them all at tier 3 and a new event could block none of them (blackout only
		 * works downwards). Capacity still limits seats (filters 1 and 2); the tier comes from the
		 * "Special event" field.
		 */
		if ( $this->config->is_special_event( $canon ) ) {
			return 3;
		}
		if ( 'flex' === $this->config->get_product_type( $canon ) ) {
			return 1;
		}
		return 2;
	}

	/**
	 * Build once per request-and-tier the processed bookable=no rules covering every bookable
	 * range of every anchor product with a tier STRICTLY ABOVE the requesting one. Reads each
	 * source product's RAW get_availability() (never get_availability_rules() — that re-fires
	 * filter 3), keeps every bookable=yes entry with its type/from/to/rrule/dates intact and flips
	 * it to bookable=no, then processes the set at product level. Memoized per requesting tier:
	 * the rule sets are static within a request but differ between tiers.
	 *
	 * @param int $tier Tier of the REQUESTING product (see tier()).
	 * @return array<int,array>
	 */
	private function get_blackout_rules_above( $tier ) {
		$tier = (int) $tier;
		if ( isset( $this->blackout_rules[ $tier ] ) ) {
			return $this->blackout_rules[ $tier ];
		}
		$this->blackout_rules[ $tier ] = array();

		if ( ! class_exists( 'WC_Product_Booking_Rule_Manager' ) || ! function_exists( 'wc_get_product' ) ) {
			return $this->blackout_rules[ $tier ];
		}

		$flipped = array();
		foreach ( $this->config->get_product_ids() as $pid ) {
			if ( $this->tier( $pid ) <= $tier ) {
				continue;
			}
			$source = wc_get_product( $pid );
			if ( ! is_object( $source ) || ! method_exists( $source, 'get_availability' ) ) {
				continue;
			}
			foreach ( (array) $source->get_availability() as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['type'] ) || empty( $entry['bookable'] ) || 'yes' !== $entry['bookable'] ) {
					// Only well-formed bookable ranges become blackout ranges. A missing/empty type would
					// fatal process_availability_rules (get_type_function('') → undefined get__range) inside
					// the live filter; empty() covers both the absent key and the empty-string case.
					continue;
				}
				// Keep the event's own type/from/to/rrule/dates/priority; only flip bookable. Precedence
				// comes purely from POSITION: these are appended AFTER the already-sorted rules, giving
				// them the highest override index in get_minutes_from_rules (later rule wins —
				// class-wc-product-booking-rule-manager.php:536,563). We deliberately do NOT set a
				// priority: sort_rules_callback's semantics are inverted (lower priority = higher
				// override, :983) and no post-filter code re-sorts the array in this frozen 3.7.0 fork,
				// so a priority value would be at best inert and at worst point the wrong way.
				$entry['bookable'] = 'no';
				$flipped[]         = $entry;
			}
		}

		if ( ! empty( $flipped ) ) {
			$this->blackout_rules[ $tier ] = (array) WC_Product_Booking_Rule_Manager::process_availability_rules( $flipped, 'product' );
		}

		return $this->blackout_rules[ $tier ];
	}
}
