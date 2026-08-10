<?php
/**
 * Shortcode — [wbc_grid_rezerwacji]. Enqueues the grid, localizes the payload, prints the shell.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * No custom REST/AJAX endpoint in slice 1 → no read nonce to protect. Data travels via
 * wp_localize_script; the CTA form-POST goes to the native Woo add-to-cart handler.
 */
final class WBC_Grid_Shortcode {

	const HANDLE = 'wbc-nordic-grid';

	/** @var WBC_Grid_Data */
	private $grid_data;

	/**
	 * @param WBC_Grid_Data $grid_data
	 */
	public function __construct( WBC_Grid_Data $grid_data ) {
		$this->grid_data = $grid_data;
		add_shortcode( 'wbc_grid_rezerwacji', array( $this, 'render' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts
	 * @return string
	 */
	public function render( $atts = array() ) {
		unset( $atts );

		// Live availability counters must never be page-cached.
		do_action( 'litespeed_control_set_nocache', 'wbc grid live counters' );

		$lang    = apply_filters( 'wpml_current_language', null );
		$payload = $this->grid_data->build_payload( $lang );

		wp_enqueue_style(
			self::HANDLE,
			WOOBOOKINGS_CUSTOM_URL . 'assets/grid.css',
			array(),
			WOOBOOKINGS_CUSTOM_VERSION
		);
		// The slot helpers are a separate file so they can be unit-tested in Node without a DOM.
		wp_enqueue_script(
			self::HANDLE . '-slots',
			WOOBOOKINGS_CUSTOM_URL . 'assets/slots.js',
			array(),
			WOOBOOKINGS_CUSTOM_VERSION,
			true
		);
		wp_enqueue_script(
			self::HANDLE,
			WOOBOOKINGS_CUSTOM_URL . 'assets/grid.js',
			array( self::HANDLE . '-slots' ),
			WOOBOOKINGS_CUSTOM_VERSION,
			true
		);
		wp_localize_script( self::HANDLE, 'WBCGrid', $payload );

		$i18n = isset( $payload['i18n'] ) && is_array( $payload['i18n'] ) ? $payload['i18n'] : array();

		$daypicker_label = isset( $i18n['daypickerLabel'] ) ? $i18n['daypickerLabel'] : __( 'Day selection', 'woobookings-custom' );
		$loading         = isset( $i18n['loading'] ) ? $i18n['loading'] : __( 'Loading slots…', 'woobookings-custom' );
		$prev_label      = isset( $i18n['prevDays'] ) ? $i18n['prevDays'] : __( 'Earlier slots', 'woobookings-custom' );
		$next_label      = isset( $i18n['nextDays'] ) ? $i18n['nextDays'] : __( 'Later slots', 'woobookings-custom' );
		$month_label     = isset( $i18n['monthLabel'] ) ? $i18n['monthLabel'] : __( 'Month', 'woobookings-custom' );

		/*
		 * Navigation controls are rendered `hidden` and only revealed by the script that actually
		 * implements them. This is a feature guard in both directions: a cached older bundle never
		 * reveals them, so a live shop cannot end up with dead buttons, and a newer bundle served a
		 * payload without navigation data simply stays on the single page of days.
		 *
		 * The list deliberately carries no aria-live. It is re-rendered on every range change, and
		 * a live region plus a full re-render means dumping the whole list into the screen reader.
		 * Range, day and emptiness are announced by the dedicated status region below: one writer
		 * per live region, with the cart toast kept as a separate channel.
		 */
		ob_start();
		?>
		<div class="wbc-grid" id="wbc-grid">
			<div class="wbc-grid__nav">
				<button type="button" class="wbc-grid__pager wbc-grid__pager--prev" hidden aria-label="<?php echo esc_attr( $prev_label ); ?>" aria-controls="wbc-grid-list"><span aria-hidden="true">&lsaquo;</span></button>
				<div class="wbc-grid__daypicker" role="tablist" aria-label="<?php echo esc_attr( $daypicker_label ); ?>"></div>
				<button type="button" class="wbc-grid__pager wbc-grid__pager--next" hidden aria-label="<?php echo esc_attr( $next_label ); ?>" aria-controls="wbc-grid-list"><span aria-hidden="true">&rsaquo;</span></button>
				<div class="wbc-grid__month" hidden>
					<label class="wbc-grid__month-label" for="wbc-grid-month-select"><?php echo esc_html( $month_label ); ?></label>
					<select class="wbc-grid__month-select" id="wbc-grid-month-select"></select>
				</div>
			</div>
			<div class="wbc-grid__list" id="wbc-grid-list" aria-busy="true">
				<p class="wbc-grid__loading"><?php echo esc_html( $loading ); ?></p>
			</div>
			<div class="wbc-grid__status" role="status" aria-atomic="true"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
