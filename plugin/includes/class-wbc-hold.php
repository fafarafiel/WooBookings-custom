<?php
/**
 * Hold window for a slot waiting in the cart.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adding to the cart creates a booking in `in-cart` status, and that booking removes seats from
 * the pool immediately. With no time limit, dropping a slot into a cart and walking away would
 * block that hour forever, so the vendor schedules a cleanup event that deletes the booking and
 * releases the seats once the window expires.
 *
 * This class does two things and both must quote the SAME number of minutes:
 *   1. skraca vendorowe okno z 60 do naszego,
 *   2. it hands that value to the presentation layer, so the message a customer reads cannot
 *      drift away from the real expiry.
 *
 * The number therefore lives in ONE place and must never be typed a second time into the copy:
 * the message is composed with sprintf from the same constant.
 */
final class WBC_Hold {

	/**
	 * How long a customer has to finish before the slot returns to the pool.
	 *
	 * Fifteen minutes rather than ten. The vendor warns on its own filter that a short window can
	 * delete the booking BEFORE payment completes, and a redirect-based bank payment (log in,
	 * authorise, come back) easily eats ten. Expiring mid-payment lands in the expensive
	 * "paid after the hold expired" case, which has no automatic refund and needs a phone call.
	 */
	const MINUTES = 15;

	/**
	 * Register filters.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_bookings_remove_inactive_cart_time', array( $this, 'hold_minutes' ) );
	}

	/**
	 * Shorten the vendor's 60 minute window to ours.
	 *
	 * @param int $minutes Vendor value, 60 by default.
	 * @return int
	 */
	public function hold_minutes( $minutes ) {
		return self::MINUTES;
	}

	/**
	 * Zdanie pokazywane klientowi po dodaniu do koszyka.
	 *
	 * The number is injected with sprintf from the same constant that drives the filter, so
	 * changing it moves the real expiry and the customer-facing copy together. A translator
	 * replaces the sentence, never the number.
	 *
	 * @return string
	 */
	public static function notice_text() {
		return sprintf(
			/* translators: %d: minutes left to complete the booking. */
			__( 'Your slot is held. You have %d minutes to complete payment, after which the hold is released and the slot returns to the pool.', 'woobookings-custom' ),
			self::MINUTES
		);
	}
}
