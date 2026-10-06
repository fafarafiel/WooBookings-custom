<?php
/**
 * Cache flush — the REST /products/slots transient is cleared on add-to-cart but NOT on the
 * "downward" paths (trash, cancel, refund, hold expiry). Those must flush explicitly.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * On any booking teardown, drop the slots transient for BOTH products on the shared resource —
 * cancelling a ceremony must also refresh the flex rental slot (shared capacity pool).
 */
final class WBC_Cache_Flush {

	/** @var WBC_Config */
	private $config;

	/** @var WBC_WPML_Guard */
	private $wpml_guard;

	/** @var int[] Product IDs saved in this request, awaiting the deferred (shutdown) config flush. */
	private $pending_saves = array();

	/**
	 * @param WBC_Config     $config
	 * @param WBC_WPML_Guard $wpml_guard
	 */
	public function __construct( WBC_Config $config, WBC_WPML_Guard $wpml_guard ) {
		$this->config     = $config;
		$this->wpml_guard = $wpml_guard;

		/*
		 * Hook names are verified against the Bookings 3.7.0 source, not guessed from the status
		 * vocabulary. Status hooks fire as 'woocommerce_booking_' . $status (class-wc-booking.php:369),
		 * and the booking status set (class-wc-bookings-init.php:250-334) contains NEITHER 'trashed'
		 * NOR 'refunded' — the previous wiring to those two names was dead code. Deletion travels
		 * through the data store under its own names (class-wc-booking-data-store.php:252,256).
		 */
		add_action( 'woocommerce_trash_booking', array( $this, 'flush_for_booking' ), 10, 1 );
		add_action( 'woocommerce_delete_booking', array( $this, 'flush_for_booking' ), 10, 1 );
		add_action( 'woocommerce_booking_cancelled', array( $this, 'flush_for_booking' ), 10, 1 );

		/*
		 * A refund does not need its own hook: refunding the order transitions the booking to
		 * 'cancelled' (class-wc-booking-order-manager.php:1254), which the line above already covers.
		 */

		/*
		 * in-cart → was-in-cart fires when the CUSTOMER removes the item from the cart
		 * (class-wc-booking-cart-manager.php:284) — not on hold expiry.
		 */
		add_action( 'woocommerce_booking_in-cart_to_was-in-cart', array( $this, 'flush_for_booking' ), 10, 1 );

		/*
		 * Bulk "Move to Trash" from the wp-admin bookings list calls core wp_trash_post() per post
		 * and never touches the Bookings data store, so none of the hooks above fire. Without this,
		 * a freed seat stays "booked" in the grid for the full 1h transient TTL.
		 */
		add_action( 'trashed_post', array( $this, 'flush_if_booking' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'flush_if_booking' ), 10, 1 );

		/*
		 * Hold expiry, for example an online payment that is never completed, is the one path no
		 * hook above reaches. The cron calls core wp_delete_post() directly
		 * (class-wc-booking-cron-manager.php:93), which:
		 *   - bypasses the data store, so 'woocommerce_delete_booking' (only do_action site is
		 *     class-wc-booking-data-store.php:252) never fires;
		 *   - never routes to the trash, because core sends only 'post' and 'page' to wp_trash_post()
		 *     — 'wc_booking' is a CPT, so it is deleted outright and 'trashed_post' never fires.
		 * The cron does clear the transient itself, but only for the booking's OWN product_id, which
		 * leaves the second product on the shared resource stale for the full TTL. Cross-product
		 * flush is this class's entire reason to exist, so it must catch the delete itself.
		 *
		 * 'before_delete_post' and NOT 'deleted_post': flush_if_booking() identifies the post via
		 * get_post_type(), which returns false once the row is gone — on 'deleted_post' the guard
		 * would reject every booking and the flush would silently never run.
		 */
		add_action( 'before_delete_post', array( $this, 'flush_if_booking' ), 10, 1 );

		/*
		 * CONFIGURATION save, not just booking teardown. Under the tier hierarchy the open
		 * product's slots are DERIVED from the ceremony's availability rules, while the vendor's
		 * save path clears only the saved product's own transient
		 * (data-stores/class-wc-product-booking-data-store-cpt.php:107)
		 * and clear_cache() on save_post does not touch booking_slots_ transients
		 * (vendor cache class). An editor changing a session's hours would leave
		 * the open product's slots stale for the full TTL (collective transient 1h). Any anchor
		 * product save → flush them all. Both hooks feed one collector; the second covers any
		 * exotic path that persists product meta without a post update.
		 */
		add_action( 'save_post_product', array( $this, 'flush_for_product_save' ), 20, 1 );
		add_action( 'woocommerce_process_product_meta', array( $this, 'flush_for_product_save' ), 20, 1 );
	}

	/**
	 * Collect a saved product for the DEFERRED configuration flush. The actual flush must not
	 * run here: save_post_product fires BEFORE the meta-box/CRUD writes land (resource pinning,
	 * availability, product type term), so reading get_product_ids() mid-save would trigger
	 * discovery against half-written state and PERSIST that stale list in the discovery option.
	 * Deferring to shutdown reads the world only after every write
	 * of the request has landed — that also covers trashing an anchor product (post-save
	 * discovery no longer lists it, but the flush must still fire for its siblings).
	 *
	 * @param int $post_id Saved product post ID (any language).
	 * @return void
	 */
	public function flush_for_product_save( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || in_array( $post_id, $this->pending_saves, true ) ) {
			return;
		}
		if ( empty( $this->pending_saves ) ) {
			add_action( 'shutdown', array( $this, 'flush_pending_saves' ) );
		}
		$this->pending_saves[] = $post_id;
	}

	/**
	 * Shutdown half of the deferred configuration flush. Membership is deliberately broader
	 * than "in get_product_ids()": the saved product may have just LEFT the anchor (trash,
	 * resource unpinned) and the discovery list no longer names it, yet its former siblings'
	 * slots are exactly what went stale. Any saved product that (canonically) resolves to a
	 * bookable product triggers the flush — over-flushing is a few idempotent transient
	 * deletes, silent staleness is a wrong grid for a full TTL hour.
	 *
	 * @return void
	 */
	public function flush_pending_saves() {
		$saved                = $this->pending_saves;
		$this->pending_saves  = array();

		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		foreach ( $saved as $post_id ) {
			$canon   = $this->wpml_guard->wbc_canonical_id( $post_id );
			$product = wc_get_product( $canon );
			if ( is_object( $product ) && is_a( $product, 'WC_Product_Booking' ) ) {
				$this->flush_for_booking();
				return;
			}
		}
	}

	/**
	 * Flush only when the post that moved really is a booking. Bound to core post hooks, which fire
	 * for every post type on the site, so the type guard is the whole point.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public function flush_if_booking( $post_id ) {
		if ( 'wc_booking' !== get_post_type( (int) $post_id ) ) {
			return;
		}
		$this->flush_for_booking( (int) $post_id );
	}

	/**
	 * Flush the slots transient for every product on the resource. Idempotent; guarded on the
	 * Bookings cache class. Zero DB work of our own — delegates to the official cache API.
	 *
	 * @param int $booking_id Unused (both products flush regardless of which booking triggered).
	 * @return void
	 */
	public function flush_for_booking( $booking_id = 0 ) {
		if ( ! class_exists( 'WC_Bookings_Cache' ) || ! is_callable( array( 'WC_Bookings_Cache', 'delete_booking_slots_transient' ) ) ) {
			return;
		}

		foreach ( $this->config->get_product_ids() as $product_id ) {
			WC_Bookings_Cache::delete_booking_slots_transient( (int) $product_id );
		}
	}
}
