<?php
/**
 * One-time review of products whose meaning changed in 1.1.0.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * In 1.0.0 any product with a seat cap was a special event and blocked the rest of the evening.
 * Since 1.1.0 the blackout follows the explicit "Special event" marker, and a cap alone only
 * limits seats. The data cannot say whether a capped product without the marker is a 1.0.0 event
 * or a regular session with a cap, so the plugin does not guess. An admin notice lists those
 * products and lets the shop owner either mark them as special events or confirm that they are
 * regular sessions. Either choice is recorded and the notice does not come back.
 *
 * Until the answer, a listed 1.0.0 event does not block the evening, because the runtime reads
 * only the marker. The notice says so and is shown as an error on every admin page. Neither button
 * is the default: on a new site the same notice appears for a regular session given a seat cap.
 */
final class WBC_Event_Review {

	const OPTION_DONE = 'woobookings_custom_events_reviewed';
	const ACTION      = 'wbc_review_events';

	/** @var WBC_Config */
	private $config;

	/** @var WBC_Cache_Flush */
	private $cache_flush;

	/**
	 * @param WBC_Config      $config      Plugin configuration.
	 * @param WBC_Cache_Flush $cache_flush Slots transient flusher.
	 */
	public function __construct( WBC_Config $config, WBC_Cache_Flush $cache_flush ) {
		$this->config      = $config;
		$this->cache_flush = $cache_flush;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Products to review: products on the resource with a seat cap and no marker, open entry
	 * excepted (it can never be a special event). Empty once the review has been answered.
	 *
	 * @return int[]
	 */
	public function pending_ids() {
		if ( '1' === (string) get_option( self::OPTION_DONE, '' ) ) {
			return array();
		}
		$ids = array();
		foreach ( (array) $this->config->get_product_ids() as $id ) {
			$id = (int) $id;
			if ( self::needs_review(
				$this->config->get_product_type( $id ),
				$this->config->get_event_capacity( $id ),
				get_post_meta( $id, WBC_Config::META_IS_EVENT, true )
			) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Whether a product has a seat cap but no Special event marker.
	 *
	 * @param string $type     'flex' or 'ceremony'.
	 * @param int    $capacity Seat cap on the canonical product.
	 * @param mixed  $marker   Current META_IS_EVENT value.
	 * @return bool
	 */
	public static function needs_review( $type, $capacity, $marker ) {
		return 'flex' !== $type && (int) $capacity > 0 && '1' !== (string) $marker;
	}

	/**
	 * The review notice, with one button per answer and the listed IDs as hidden fields.
	 *
	 * @return void
	 */
	public function notice() {
		if ( ! current_user_can( WBC_Settings::CAPABILITY ) ) {
			return;
		}
		$ids = $this->pending_ids();
		if ( empty( $ids ) ) {
			return;
		}

		$names = array();
		foreach ( $ids as $id ) {
			$names[] = get_the_title( $id );
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'WooBookings Custom:', 'woobookings-custom' ) . '</strong> ';
		echo esc_html__( 'these products have a seat cap but no "Special event" tick. Since 1.1.0 only that tick blocks the whole evening, so right now they do not block it. If you updated from 1.0.0 and they are events, mark them; if they are regular sessions with a seat limit, say so. Only published products on the resource are listed, so check drafts, scheduled, pending and private products in the product editor.', 'woobookings-custom' );
		echo '</p><p>' . esc_html( implode( ', ', $names ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="ids[]" value="' . esc_attr( (string) $id ) . '" />';
		}
		wp_nonce_field( self::ACTION );
		echo '<button type="submit" class="button" name="choice" value="mark">' . esc_html__( 'Mark them as special events', 'woobookings-custom' ) . '</button> ';
		echo '<button type="submit" class="button" name="choice" value="keep">' . esc_html__( 'They are regular sessions', 'woobookings-custom' ) . '</button>';
		echo '</p></form></div>';
	}

	/**
	 * admin-post handler for the two buttons.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( WBC_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'woobookings-custom' ) );
		}
		check_admin_referer( self::ACTION );

		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
		$shown  = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		$this->apply( $choice, $shown );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * Apply an answer. Only products that were shown in the notice AND still match its rule are
	 * marked, so a product capped after the notice was rendered is never changed unseen.
	 *
	 * @param string $choice 'mark' or 'keep'; anything else is ignored.
	 * @param int[]  $shown  Product IDs the notice listed.
	 * @return int Number of products marked.
	 */
	public function apply( $choice, array $shown = array() ) {
		if ( 'mark' !== $choice && 'keep' !== $choice ) {
			return 0;
		}
		$marked = 0;
		if ( 'mark' === $choice ) {
			$shown = array_map( 'intval', $shown );
			foreach ( $this->pending_ids() as $id ) {
				if ( ! in_array( $id, $shown, true ) ) {
					continue;
				}
				update_post_meta( $id, WBC_Config::META_IS_EVENT, '1' );
				++$marked;
			}
			if ( $marked > 0 ) {
				// update_post_meta() does not fire save_post, so the slots cache would keep showing
				// the evening as open until something else cleared it.
				$this->cache_flush->flush_for_booking();
			}
		}
		update_option( self::OPTION_DONE, '1' );
		return $marked;
	}
}
