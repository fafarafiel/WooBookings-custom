<?php
/**
 * Price choke-point, intentionally inert.
 *
 * The vendor's native "multiply cost by person count" already produces the required price, so
 * hooking a pass-through onto a hot-path filter would add cost with no behaviour change. The
 * class exists so that any future price rule has exactly one place to live,
 * ready to activate when real pricing logic arrives — but is intentionally NOT hooked yet.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * woocommerce_bookings_calculated_booking_cost is the terminal choke-point of the only function
 * that computes real booking cost. When pricing logic lands, call register() and implement
 * filter_booking_cost(); until then it stays off the hot path.
 */
final class WBC_Cost {

	/**
	 * Deliberate no-op in slice 1 — the native cost_multiplier owns pricing here. Enable when a
	 * pricing plasterek adds real logic.
	 *
	 * @return void
	 */
	public function register() {
		// add_filter( 'woocommerce_bookings_calculated_booking_cost', array( $this, 'filter_booking_cost' ), 10, 3 );
		return;
	}

	/**
	 * Terminal price callback. Must always return a non-negative float — a WP_Error or
	 * non-numeric value is silently treated as cost 0 in the admin. The clamp lives before the
	 * filter in core, so we re-assert it here.
	 *
	 * @param mixed                 $cost    Cost as passed by core (already after person multiplier).
	 * @param object                $product WC_Product_Booking.
	 * @param array<string,mixed>   $data    Booking data (_persons, _resource_id, _duration, …).
	 * @return float
	 */
	public function filter_booking_cost( $cost, $product = null, $data = array() ) {
		return (float) max( 0, (float) $cost );
	}
}
