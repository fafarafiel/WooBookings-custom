<?php
/**
 * Host assignment, editable without code from the product editor.
 *
 * A host can change PER SLOT, and is picked from the shared registry. The screen is a MIRROR of
 * the schedule rather than a second calendar: rows are generated from the product's own weekly
 * availability rules, so nobody ever types a date or an hour. They pick a person next to a slot
 * the system already knows about, and changing the schedule rebuilds the list automatically.
 *
 * Real schedules are lopsided. In the reference deployment seven products held eighteen weekly
 * slots, and three of them ran once a week, where per-slot and per-product are the same thing.
 * Hence the DEFAULT field for the whole product: the common case costs one choice per product,
 * with overrides only where somebody genuinely differs.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and persists the default host plus per-slot overrides.
 *
 * Deliberately separate from WBC_Event_Fields. That class owns its own contract and its own save
 * path; merging them would tie two unrelated settings to a single write.
 */
final class WBC_Host_Fields {

	/**
	 * @var WBC_Hosts
	 */
	private $hosts;

	public function __construct( WBC_Hosts $hosts ) {
		$this->hosts = $hosts;
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_fields' ) );
	}

	/**
	 * Row key: `ISOWeekday|HH:MM`, for example `6|18:15`.
	 *
	 * @param int    $weekday ISO 1 (Monday) to 7 (Sunday).
	 * @param string $time    HH:MM.
	 * @return string
	 */
	public static function slot_key( $weekday, $time ) {
		return (int) $weekday . '|' . $time;
	}

	/**
	 * The product's weekly slots, read from its own availability rules.
	 *
	 * Bookings stores a schedule as `time:N` rules, where N is the ISO weekday, with a `from`
	 * field. Only enabling rules are taken: a disabling rule is a gap in the schedule, not a slot
	 * anybody can be assigned to.
	 *
	 * @param WC_Product $product Produkt (kanon PL).
	 * @return array<int,array{key:string,weekday:int,time:string}> Sorted by day then time.
	 */
	public function get_weekly_slots( $product ) {
		if ( ! $product || ! is_callable( array( $product, 'get_availability' ) ) ) {
			return array();
		}

		$rules = $product->get_availability();
		if ( ! is_array( $rules ) ) {
			return array();
		}

		$slots = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['type'] ) ) {
				continue;
			}
			if ( ! preg_match( '/^time:([1-7])$/', (string) $rule['type'], $m ) ) {
				continue;
			}
			$bookable = isset( $rule['bookable'] ) ? (string) $rule['bookable'] : 'no';
			if ( 'yes' !== $bookable ) {
				continue;
			}
			$from = isset( $rule['from'] ) ? substr( (string) $rule['from'], 0, 5 ) : '';
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $from ) ) {
				continue;
			}

			$weekday = (int) $m[1];
			$key     = self::slot_key( $weekday, $from );

			$slots[ $key ] = array(
				'key'     => $key,
				'weekday' => $weekday,
				'time'    => $from,
			);
		}

		uasort(
			$slots,
			static function ( $a, $b ) {
				return ( $a['weekday'] <=> $b['weekday'] ) ?: strcmp( $a['time'], $b['time'] );
			}
		);

		return array_values( $slots );
	}

	/**
	 * Weekday names in ISO order.
	 *
	 * @return array<int,string>
	 */
	private function weekday_names() {
		return array(
			1 => __( 'Monday', 'woobookings-custom' ),
			2 => __( 'Tuesday', 'woobookings-custom' ),
			3 => __( 'Wednesday', 'woobookings-custom' ),
			4 => __( 'Thursday', 'woobookings-custom' ),
			5 => __( 'Friday', 'woobookings-custom' ),
			6 => __( 'Saturday', 'woobookings-custom' ),
			7 => __( 'Sunday', 'woobookings-custom' ),
		);
	}

	/**
	 * Default host field plus the per-slot override table.
	 *
	 * @return void
	 */
	public function render_fields() {
		global $thepostid, $post;

		$post_id = $thepostid ? (int) $thepostid : ( $post ? (int) $post->ID : 0 );
		if ( ! $post_id ) {
			return;
		}

		$choices = $this->hosts->get_choices();

		echo '<div class="options_group show_if_booking">';

		if ( empty( $choices ) ) {
			echo '<p style="padding:0 12px 12px;">';
			printf(
				/* translators: %s: link to the host list. */
				esc_html__( 'No hosts yet. Add people in %s, then come back here to assign them to slots.', 'woobookings-custom' ),
				'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . WBC_Hosts::POST_TYPE ) ) . '">' . esc_html__( 'Hosts', 'woobookings-custom' ) . '</a>'
			);
			echo '</p>';
			echo '</div>';
			return;
		}

		$default = (int) get_post_meta( $post_id, WBC_Config::META_HOST_DEFAULT, true );
		$options = array( '' => __( 'Nobody (hide)', 'woobookings-custom' ) );
		foreach ( $choices as $id => $name ) {
			$options[ (string) $id ] = $name;
		}

		woocommerce_wp_select(
			array(
				'id'          => WBC_Config::META_HOST_DEFAULT,
				'value'       => $default ? (string) $default : '',
				'label'       => __( 'Host (default)', 'woobookings-custom' ),
				'description' => __( 'The person leading every slot of this product. Their name appears on the grid card, and clicking it opens their biography. Leave empty to show nobody.', 'woobookings-custom' ),
				'desc_tip'    => true,
				'options'     => $options,
			)
		);

		$slots = $this->get_weekly_slots( wc_get_product( $post_id ) );
		if ( empty( $slots ) ) {
			echo '<p style="padding:0 12px 12px;">' . esc_html__( 'This product has no weekly schedule yet, so there are no slots to override. Set availability on the Availability tab and the list will appear here on its own.', 'woobookings-custom' ) . '</p>';
			echo '</div>';
			return;
		}

		$map   = get_post_meta( $post_id, WBC_Config::META_HOST_MAP, true );
		$map   = is_array( $map ) ? $map : array();
		$names = $this->weekday_names();

		$per_slot = array( '' => __( 'Same as default', 'woobookings-custom' ) );
		foreach ( $choices as $id => $name ) {
			$per_slot[ (string) $id ] = $name;
		}

		echo '<p style="padding:0 12px 4px;"><strong>' . esc_html__( 'Who leads each slot', 'woobookings-custom' ) . '</strong><br>';
		echo '<span class="description">' . esc_html__( 'This list comes straight from the product schedule: change the hours on the Availability tab and it changes here too. Fill in only the slots led by somebody other than the default.', 'woobookings-custom' ) . '</span></p>';

		echo '<table class="widefat striped" style="margin:0 12px 12px; width:calc(100% - 24px);"><tbody>';
		foreach ( $slots as $slot ) {
			$key      = $slot['key'];
			$selected = isset( $map[ $key ] ) ? (string) (int) $map[ $key ] : '';
			$label    = $names[ $slot['weekday'] ] . ', ' . $slot['time'];

			echo '<tr><th scope="row" style="width:40%;">' . esc_html( $label ) . '</th><td>';
			echo '<select name="' . esc_attr( WBC_Config::META_HOST_MAP ) . '[' . esc_attr( $key ) . ']" style="width:100%;max-width:320px;">';
			foreach ( $per_slot as $value => $text ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $selected, (string) $value, false ) . '>' . esc_html( $text ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		echo '</div>';
	}

	/**
	 * Persists both meta keys. WooCommerce verifies its own `woocommerce_meta_nonce` before firing
	 * tego hooka — sprawdzamy go i uprawnienie jeszcze raz, obronnie (konwencja WooBookings Custom).
	 *
	 * @param int $post_id ID produktu.
	 * @return void
	 */
	public function save_fields( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$default = isset( $_POST[ WBC_Config::META_HOST_DEFAULT ] ) ? absint( wp_unslash( $_POST[ WBC_Config::META_HOST_DEFAULT ] ) ) : 0;
		if ( $default && null === $this->hosts->get_host( $default ) ) {
			$default = 0;
		}
		if ( $default ) {
			update_post_meta( $post_id, WBC_Config::META_HOST_DEFAULT, $default );
		} else {
			delete_post_meta( $post_id, WBC_Config::META_HOST_DEFAULT );
		}

		$map = array();
		if ( isset( $_POST[ WBC_Config::META_HOST_MAP ] ) && is_array( $_POST[ WBC_Config::META_HOST_MAP ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- key and value are sanitised below.
			foreach ( wp_unslash( $_POST[ WBC_Config::META_HOST_MAP ] ) as $raw_key => $raw_value ) {
				$key = (string) $raw_key;
				// The key must match `N|HH:MM` exactly or it is discarded. POST data is never trusted.
				if ( ! preg_match( '/^[1-7]\|\d{2}:\d{2}$/', $key ) ) {
					continue;
				}
				$host_id = absint( $raw_value );
				if ( ! $host_id || null === $this->hosts->get_host( $host_id ) ) {
					continue;
				}
				$map[ $key ] = $host_id;
			}
		}

		if ( ! empty( $map ) ) {
			update_post_meta( $post_id, WBC_Config::META_HOST_MAP, $map );
		} else {
			delete_post_meta( $post_id, WBC_Config::META_HOST_MAP );
		}
	}
}
