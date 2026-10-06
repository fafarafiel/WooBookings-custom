<?php
/**
 * Event fields — codeless "Event capacity" + "Special event" inputs in the bookable product editor.
 *
 * Two independent switches, set from wp-admin without touching code or the companion configuration:
 * the seat cap (WBC_Config::META_EVENT_CAPACITY, for example 10 on a 20-seat resource) and the
 * whole-evening exclusivity marker (WBC_Config::META_IS_EVENT). Both are hidden postmeta, never a
 * category, which WPML would translate and break on translated products. The marker is not offered
 * on an open-entry product with a customer-chosen duration: a ticked open entry would sit at the
 * top tier and black out every regular session.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and persists the event-capacity and special-event fields. The field lives in the General product-data
 * panel, wrapped in show_if_booking so it appears only for bookable products — the same panel and
 * visibility convention Bookings itself uses (class-wc-bookings-admin.php:37, html-booking-data.php).
 */
final class WBC_Event_Fields {

	public function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_field' ) );
	}

	/**
	 * Render the "Event capacity (seats)" number field and the "Special event" checkbox for bookable products.
	 *
	 * @return void
	 */
	public function render_field() {
		global $thepostid, $post;

		$post_id  = $thepostid ? (int) $thepostid : ( $post ? (int) $post->ID : 0 );
		$value    = $post_id ? get_post_meta( $post_id, WBC_Config::META_EVENT_CAPACITY, true ) : '';
		$is_event = $post_id ? get_post_meta( $post_id, WBC_Config::META_IS_EVENT, true ) : '';

		echo '<div class="options_group show_if_booking">';
		woocommerce_wp_text_input(
			array(
				'id'                => WBC_Config::META_EVENT_CAPACITY,
				'value'             => '' === $value ? '' : (string) (int) $value,
				'label'             => __( 'Event capacity (seats)', 'woobookings-custom' ),
				'description'       => __( 'How many people can book one slot of this session. Empty or 0 means the full capacity of the shared resource. This field only sets the seat limit; whether the session blocks the whole evening is decided by the "Special event" field below.', 'woobookings-custom' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		/*
		 * Blocking the evening is a DELIBERATE choice, not a side effect of typing a number: regular
		 * sessions have a seat limit too, and they must not block each other. A checkbox, because the
		 * editor answers one question: "should nothing else run at this time?".
		 *
		 * Open entry (duration chosen by the customer) does not get the field: the missing POST key on
		 * save clears any old marker, and the runtime ignores it there anyway.
		 */
		$product = wc_get_product( $post_id );
		$is_flex = is_object( $product ) && method_exists( $product, 'get_duration_type' )
			&& 'customer' === $product->get_duration_type();
		if ( $is_flex ) {
			echo '<p class="form-field"><span class="description">' . esc_html__( 'The "Special event" field does not apply to open entry.', 'woobookings-custom' ) . '</span></p>';
			echo '</div>';
			return;
		}
		woocommerce_wp_checkbox(
			array(
				'id'          => WBC_Config::META_IS_EVENT,
				'value'       => '1' === (string) $is_event ? 'yes' : 'no',
				'cbvalue'     => 'yes',
				'label'       => __( 'Special event', 'woobookings-custom' ),
				'description' => __( 'Blocks the whole evening: while this event runs, regular sessions and open entry are unavailable. Tick it for a one-off session with a guest host or any other event that should have the resource to itself.', 'woobookings-custom' ),
				'desc_tip'    => true,
			)
		);
		echo '</div>';
	}

	/**
	 * Persist the event-capacity and special-event metas on product save. WooCommerce verifies its own
	 * woocommerce_meta_nonce before firing this hook; re-check it and the capability defensively.
	 * Empty/0 clears the capacity meta (full pool); a missing checkbox key clears the marker.
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

		// An unticked checkbox is not sent in POST: a missing key means "untick", not "no change".
		$is_event = isset( $_POST[ WBC_Config::META_IS_EVENT ] )
			&& 'yes' === sanitize_key( wp_unslash( $_POST[ WBC_Config::META_IS_EVENT ] ) );

		if ( $is_event ) {
			update_post_meta( $post_id, WBC_Config::META_IS_EVENT, '1' );
		} else {
			delete_post_meta( $post_id, WBC_Config::META_IS_EVENT );
		}
	}
}
