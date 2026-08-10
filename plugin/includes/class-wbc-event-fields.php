<?php
/**
 * Event fields — a codeless "Event capacity" input in the bookable product editor.
 *
 * Lets an editor turn any bookable product into a special event with its own seat cap, from wp-admin,
 * without touching code or the companion configuration (client requirement #3). The marker is a
 * hidden postmeta (WBC_Config::META_EVENT_CAPACITY), never a category (which WPML would translate
 * and break under EN/DE).
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and persists the event-capacity field. The field lives in the General product-data
 * panel, wrapped in show_if_booking so it appears only for bookable products — the same panel and
 * visibility convention Bookings itself uses (class-wc-bookings-admin.php:37, html-booking-data.php).
 */
final class WBC_Event_Fields {

	public function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_field' ) );
	}

	/**
	 * Render the "Event capacity (seats)" number field for bookable products.
	 *
	 * @return void
	 */
	public function render_field() {
		global $thepostid, $post;

		$post_id = $thepostid ? (int) $thepostid : ( $post ? (int) $post->ID : 0 );
		$value   = $post_id ? get_post_meta( $post_id, WBC_Config::META_EVENT_CAPACITY, true ) : '';

		echo '<div class="options_group show_if_booking">';
		woocommerce_wp_text_input(
			array(
				'id'                => WBC_Config::META_EVENT_CAPACITY,
				'value'             => '' === $value ? '' : (string) (int) $value,
				'label'             => __( 'Event capacity (seats)', 'woobookings-custom' ),
				'description'       => __( 'Set this for a themed session with its own seat limit. Empty or 0 means the product is not an event. An event takes the whole slot: regular sessions and open entry become unavailable while it runs.', 'woobookings-custom' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		echo '</div>';
	}

	/**
	 * Persist the event-capacity meta on product save. WooCommerce verifies its own
	 * woocommerce_meta_nonce before firing this hook; re-check it and the capability defensively.
	 * Empty/0 clears the meta (the product is not an event).
	 *
	 * @param int $post_id Product post ID.
	 * @return void
	 */
	public function save_field( $post_id ) {
		$post_id = (int) $post_id;

		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$capacity = isset( $_POST[ WBC_Config::META_EVENT_CAPACITY ] )
			? absint( wp_unslash( $_POST[ WBC_Config::META_EVENT_CAPACITY ] ) )
			: 0;

		if ( $capacity > 0 ) {
			update_post_meta( $post_id, WBC_Config::META_EVENT_CAPACITY, $capacity );
		} else {
			delete_post_meta( $post_id, WBC_Config::META_EVENT_CAPACITY );
		}
	}
}
