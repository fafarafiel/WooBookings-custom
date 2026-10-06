<?php
/**
 * Host assignment, editable without code from the product editor.
 *
 * A host can change PER SLOT, and is picked from the shared registry. The screen is a MIRROR of
 * the schedule rather than a second calendar: rows are generated from the product's own weekly
 * availability rules, so nobody ever types a date or an hour. They pick a person next to a slot
 * the system already knows about, and changing the schedule rebuilds the list automatically.
 *
 * Real schedules are lopsided: several products often run only once a week, where per-slot and
 * per-product are the same thing.
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

	const EXC_FIELD    = 'wbc_host_exc';
	const EXC_SENTINEL = 'wbc_host_exc_present';

	public function __construct( WBC_Hosts $hosts ) {
		$this->hosts = $hosts;
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_fields' ) );
		// Priority 20: WooCommerce saves the product (including the Bookings schedule) at 10, so a
		// product loaded here already has the NEW slots the exceptions are validated against.
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_exceptions' ), 20 );
		// "Duplicate" (for example when creating an event from a regular session) does not copy
		// the exceptions onto the new product.
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'exclude_from_duplicate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
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
	 * @param WC_Product $product Product (canonical).
	 * @return array<int,array{key:string,weekday:int,time:string}> Sorted by day then time.
	 */
	public function get_weekly_slots( $product ) {
		return self::weekly_slots( $product );
	}

	/**
	 * Static counterpart of get_weekly_slots(): one source of slots for the editor, the grid payload
	 * and the validator. Static because the front end has no instance of this class (it is only
	 * created in wp-admin).
	 *
	 * @param WC_Product $product Product (canonical).
	 * @return array<int,array{key:string,weekday:int,time:string}>
	 */
	public static function weekly_slots( $product ) {
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

		$this->render_exceptions( $post_id, $slots, $choices );

		echo '</div>';
	}

	/**
	 * Slots grouped by weekday: `[ISOWeekday => [HH:MM, …]]`.
	 *
	 * @param array<int,array{weekday:int,time:string}> $slots
	 * @return array<int,string[]>
	 */
	public static function times_by_weekday( $slots ) {
		$out = array();
		foreach ( $slots as $slot ) {
			$out[ (int) $slot['weekday'] ][] = (string) $slot['time'];
		}
		return $out;
	}

	/**
	 * ISO weekday (1 to 7) of a `YYYY-MM-DD` date, or 0 for an invalid date.
	 *
	 * @param string $date
	 * @return int
	 */
	public static function weekday_of( $date ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return 0;
		}
		return (int) gmdate( 'N', gmmktime( 12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] ) );
	}

	/**
	 * Dated exceptions section below the weekly table.
	 *
	 * Rules: past exceptions are NOT rendered (saving removes them anyway), and no field has `min`
	 * or `required`, because a date field must not silently block "Update" while the editor is on
	 * another tab (admin-hosts.js marks a past date instead). The server renders the time options
	 * for existing rows, so a failure of admin-hosts.js does not leave empty selects that a save
	 * with the sentinel would wipe.
	 *
	 * @param int                                   $post_id
	 * @param array<int,array{weekday:int,time:string}> $slots
	 * @param array<int,string>                     $choices
	 * @return void
	 */
	private function render_exceptions( $post_id, $slots, $choices ) {
		// The runtime reads exceptions only from the source-language product: on a translation the
		// section would edit data nobody uses. Without the section there is no sentinel either, so
		// saving a translation does not touch the meta.
		$canonical = WBC_Plugin::instance()->get_wpml_guard()->wbc_canonical_id( $post_id );
		if ( $canonical !== (int) $post_id ) {
			echo '<p style="padding:0 12px 12px;" class="description">' . esc_html__( 'Exceptions (a different person on a specific day) are set on the source-language version of this product.', 'woobookings-custom' ) . '</p>';
			return;
		}

		$by_day = self::times_by_weekday( $slots );
		$today  = WBC_Config::today_midnight()->format( 'Y-m-d' );
		$saved  = get_post_meta( $post_id, WBC_Config::META_HOST_EXCEPTIONS, true );
		$saved  = is_array( $saved ) ? $saved : array();
		ksort( $saved );

		$hosts = array( '' => __( 'Choose a person (empty removes the row)', 'woobookings-custom' ) );
		foreach ( $choices as $id => $name ) {
			$hosts[ (string) $id ] = $name;
		}

		echo '<div class="wbc-host-exc" style="padding:0 12px 12px;" data-slots="' . esc_attr( wp_json_encode( (object) $by_day ) ) . '" data-today="' . esc_attr( $today ) . '" data-no-slot="' . esc_attr__( 'this session does not run on that day', 'woobookings-custom' ) . '" data-past="' . esc_attr__( 'past date, the row will be skipped', 'woobookings-custom' ) . '">';
		echo '<p style="margin:12px 0 4px;"><strong>' . esc_html__( 'Exceptions: a different person on a specific day', 'woobookings-custom' ) . '</strong><br>';
		echo '<span class="description">' . esc_html__( 'For example, a guest leads one session. Choose a date, a time from the schedule and a person. The exception applies on that day only and disappears on its own afterwards.', 'woobookings-custom' ) . '</span></p>';
		echo '<input type="hidden" name="' . esc_attr( self::EXC_SENTINEL ) . '" value="1">';

		$flag     = self::rejected_flag( $post_id );
		$rejected = (int) get_transient( $flag );
		if ( $rejected > 0 ) {
			delete_transient( $flag );
			/* translators: %d: number of rows */
			echo '<p style="color:#b32d2e;"><strong>' . esc_html( sprintf( __( 'The last save skipped %d row(s): a past date, no person, or a time outside the schedule. Check the list below.', 'woobookings-custom' ), $rejected ) ) . '</strong></p>';
		}

		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Date', 'woobookings-custom' ) . '</th><th scope="col">' . esc_html__( 'Time', 'woobookings-custom' ) . '</th><th scope="col">' . esc_html__( 'Person', 'woobookings-custom' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Remove', 'woobookings-custom' ) . '</span></th></tr></thead><tbody class="wbc-host-exc__rows">';
		$index = 0;
		foreach ( $saved as $key => $host_id ) {
			if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})\|(\d{2}:\d{2})$/', (string) $key, $m ) || $m[1] < $today ) {
				continue;
			}
			$weekday = self::weekday_of( $m[1] );
			$times   = isset( $by_day[ $weekday ] ) ? $by_day[ $weekday ] : array();
			$orphan  = ! in_array( $m[2], $times, true );
			if ( $orphan ) {
				// An orphan keeps its time in the select, so saving does not lose it.
				$times[] = $m[2];
				sort( $times );
			}
			$row_hosts = $hosts;
			if ( ! isset( $row_hosts[ (string) (int) $host_id ] ) ) {
				// A person unpublished or in the trash stays in the select so that saving does not
				// silently delete the dated entry. The front end skips such a person anyway.
				/* translators: %d: person post ID */
				$row_hosts[ (string) (int) $host_id ] = sprintf( __( 'person unavailable (ID %d): publish them or choose someone else', 'woobookings-custom' ), (int) $host_id );
			}
			$this->render_exception_row( (string) $index, $m[1], $m[2], (string) (int) $host_id, $times, $row_hosts, $orphan, (string) $key );
			++$index;
		}
		echo '</tbody></table>';

		echo '<template class="wbc-host-exc__template">';
		$this->render_exception_row( '__i__', '', '', '', array(), $hosts, false, '' );
		echo '</template>';

		echo '<p><button type="button" class="button wbc-host-exc__add" data-next="' . esc_attr( (string) $index ) . '">' . esc_html__( 'Add exception', 'woobookings-custom' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * One exception row.
	 *
	 * @param string            $index    Index in the POST array (`__i__` in the template).
	 * @param string            $date     YYYY-MM-DD or ''.
	 * @param string            $time     HH:MM or ''.
	 * @param string            $host     Person ID or ''.
	 * @param string[]          $times    Times for the select.
	 * @param array<string,string> $hosts Person options.
	 * @param bool              $orphan   The time is no longer in the schedule.
	 * @param string            $orig     Saved row key (empty for a new row).
	 * @return void
	 */
	private function render_exception_row( $index, $date, $time, $host, $times, $hosts, $orphan, $orig ) {
		$name = self::EXC_FIELD . '[' . $index . ']';

		echo '<tr class="wbc-host-exc__row"><td style="width:30%;">';
		if ( '' !== $orig ) {
			// The key before editing: if a changed date or time fails validation, saving keeps the
			// existing exception instead of losing it.
			echo '<input type="hidden" name="' . esc_attr( $name . '[orig]' ) . '" value="' . esc_attr( $orig ) . '">';
		}
		echo '<input type="date" class="wbc-host-exc__date" name="' . esc_attr( $name . '[date]' ) . '" value="' . esc_attr( $date ) . '" aria-label="' . esc_attr__( 'Date', 'woobookings-custom' ) . '">';
		echo '</td><td style="width:25%;">';
		echo '<select class="wbc-host-exc__time" name="' . esc_attr( $name . '[time]' ) . '" aria-label="' . esc_attr__( 'Time', 'woobookings-custom' ) . '">';
		foreach ( $times as $t ) {
			echo '<option value="' . esc_attr( $t ) . '"' . selected( $time, $t, false ) . '>' . esc_html( $t ) . '</option>';
		}
		echo '</select>';
		if ( $orphan ) {
			echo '<br><span style="color:#b32d2e;">' . esc_html__( 'this slot is no longer in the schedule: set it again or remove it', 'woobookings-custom' ) . '</span>';
		}
		echo '</td><td>';
		echo '<select class="wbc-host-exc__host" name="' . esc_attr( $name . '[host]' ) . '" aria-label="' . esc_attr__( 'Person', 'woobookings-custom' ) . '" style="width:100%;max-width:260px;">';
		foreach ( $hosts as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $host, (string) $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td><td style="width:1%;white-space:nowrap;">';
		echo '<button type="button" class="button-link wbc-host-exc__remove">' . esc_html__( 'Remove', 'woobookings-custom' ) . '</button>';
		echo '</td></tr>';
	}

	/**
	 * Saves the dated exceptions, separately from save_fields(), at priority 20.
	 *
	 * Without the sentinel in POST (the section did not render: no people or slots, another form)
	 * the meta is NOT touched; otherwise saving a product without a schedule would wipe the
	 * exceptions.
	 *
	 * @param int $post_id Product ID.
	 * @return void
	 */
	public function save_exceptions( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || empty( $_POST[ self::EXC_SENTINEL ] ) ) {
			return;
		}
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		$by_day  = self::times_by_weekday( self::weekly_slots( $product ) );
		$today   = WBC_Config::today_midnight()->format( 'Y-m-d' );
		$before  = get_post_meta( $post_id, WBC_Config::META_HOST_EXCEPTIONS, true );
		$before  = is_array( $before ) ? $before : array();

		$map      = array();
		$rejected = 0;
		$rows     = isset( $_POST[ self::EXC_FIELD ] ) && is_array( $_POST[ self::EXC_FIELD ] ) ? wp_unslash( $_POST[ self::EXC_FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is validated below.
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date    = isset( $row['date'] ) && is_scalar( $row['date'] ) ? sanitize_text_field( (string) $row['date'] ) : '';
			$time    = isset( $row['time'] ) && is_scalar( $row['time'] ) ? sanitize_text_field( (string) $row['time'] ) : '';
			$host_id = isset( $row['host'] ) && is_scalar( $row['host'] ) ? absint( $row['host'] ) : 0;
			$orig    = isset( $row['orig'] ) && is_scalar( $row['orig'] ) ? sanitize_text_field( (string) $row['orig'] ) : '';
			$orig    = array_key_exists( $orig, $before ) ? $orig : '';
			$key     = $date . '|' . $time;
			$existed = array_key_exists( $key, $before );

			if ( '' === $date && ! $host_id ) {
				continue; // empty row
			}
			if ( ! $host_id ) {
				// An empty person on a saved row is a deliberate removal (the path without JS).
				$rejected += ( $existed || '' !== $orig ) ? 0 : 1;
				continue;
			}

			$weekday = self::weekday_of( $date );
			$valid   = $weekday && $date >= $today && preg_match( '/^\d{2}:\d{2}$/', $time );
			// An unavailable person stays only as the same, already saved entry (it cannot be picked anew).
			$valid = $valid && ( null !== $this->hosts->get_host( $host_id ) || ( $existed && (int) $before[ $key ] === $host_id ) );
			// An orphan (its time left the schedule) stays if it was already saved. It disappears only
			// deliberately; until then the notice and the editor annotation show it.
			$valid = $valid && ( ( isset( $by_day[ $weekday ] ) && in_array( $time, $by_day[ $weekday ], true ) ) || $existed );

			if ( ! $valid ) {
				++$rejected;
				if ( '' !== $orig && substr( $orig, 0, 10 ) >= $today ) {
					// A failed edit of a saved row keeps the state from before the edit.
					$map[ $orig ] = (int) $before[ $orig ];
				}
				continue;
			}
			$map[ $key ] = $host_id;
		}

		if ( $rejected > 0 ) {
			set_transient( self::rejected_flag( $post_id ), $rejected, 5 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( self::rejected_flag( $post_id ) );
		}

		ksort( $map );
		if ( ! empty( $map ) ) {
			update_post_meta( $post_id, WBC_Config::META_HOST_EXCEPTIONS, $map );
		} else {
			delete_post_meta( $post_id, WBC_Config::META_HOST_EXCEPTIONS );
		}
	}

	/**
	 * Transient key holding the number of skipped rows, per user and product, so the message
	 * reaches the person who saved.
	 *
	 * @param int $post_id
	 * @return string
	 */
	private static function rejected_flag( $post_id ) {
		return 'wbc_exc_rejected_' . get_current_user_id() . '_' . (int) $post_id;
	}

	/**
	 * @param array $exclude Meta keys skipped by "Duplicate".
	 * @return array
	 */
	public function exclude_from_duplicate( $exclude ) {
		$exclude   = is_array( $exclude ) ? $exclude : array();
		$exclude[] = WBC_Config::META_HOST_EXCEPTIONS;
		return $exclude;
	}

	/**
	 * Script for the exceptions section, on the product edit screen only.
	 *
	 * @param string $hook_suffix
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script( 'woobookings-custom-admin-hosts', WOOBOOKINGS_CUSTOM_URL . 'assets/admin-hosts.js', array(), WOOBOOKINGS_CUSTOM_VERSION, true );
	}

	/**
	 * Persists both meta keys. WooCommerce verifies its own `woocommerce_meta_nonce` before firing
	 * this hook; the nonce and the capability are checked again here, defensively.
	 *
	 * @param int $post_id Product ID.
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
