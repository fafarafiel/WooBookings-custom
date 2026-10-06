<?php
/**
 * Orchestrator — wires the companion's components in dependency order.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton container. Component boot order is load-bearing: the WPML guard runs first so its
 * remove_action wins the race with woocommerce_new_booking, then config (needed by cache flush
 * and grid data), then the rest.
 */
final class WBC_Plugin {

	/** @var WBC_Plugin|null */
	private static $instance = null;

	/** @var WBC_WPML_Guard */
	private $wpml_guard;

	/** @var WBC_Config */
	private $config;

	/** @var WBC_Cache_Flush */
	private $cache_flush;

	/** @var WBC_Cost */
	private $cost;

	/** @var WBC_Hold */
	private $hold;

	/** @var WBC_Vendor_Strings */
	private $vendor_strings;

	/** @var WBC_Events */
	private $events;

	/** @var WBC_Settings|null */
	private $settings = null;

	/** @var WBC_Event_Fields|null */
	private $event_fields = null;

	/** @var WBC_Hosts */
	private $hosts;

	/** @var WBC_Host_Fields|null */
	private $host_fields = null;

	/** @var WBC_Grid_Data */
	private $grid_data;

	/** @var WBC_Grid_Shortcode */
	private $grid_shortcode;

	/**
	 * @return WBC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// FIRST: detach WPML duplication before any booking can fire woocommerce_new_booking.
		$this->wpml_guard = new WBC_WPML_Guard();

		$this->config      = new WBC_Config( $this->wpml_guard );
		$this->cache_flush = new WBC_Cache_Flush( $this->config, $this->wpml_guard );

		// Read layer for person-type prices shown on the card; pricing itself stays in Bookings.
		$this->cost = new WBC_Cost();

		// Polish wording for the Bookings cart refusals (vendor ships no pl_PL). Front + cart.
		$this->vendor_strings = new WBC_Vendor_Strings();
		$this->vendor_strings->register();

		// Special-event runtime (capacity cap + whole-evening exclusivity). Front + REST, so wired
		// unconditionally; the three filters self-guard on the event-capacity meta.
		$this->events = new WBC_Events( $this->config, $this->wpml_guard );

		// Hold window: shortens the vendor's 60 minutes and is the single source for the number
		// quoted in the grid message. Needed on the front end and in the cart, so it is unconditional.
		$this->hold = new WBC_Hold();
		$this->hold->register();

		// The host registry registers its post type on `init`, so it must boot on the front end too.
		// Without it get_post() would return a type WordPress does not know and the payload would
		// silently drop every biography.
		$this->hosts = new WBC_Hosts();

		if ( is_admin() ) {
			$this->settings     = new WBC_Settings( $this->config );
			$this->event_fields = new WBC_Event_Fields();
			$this->host_fields  = new WBC_Host_Fields( $this->hosts );
		}

		$this->grid_data      = new WBC_Grid_Data( $this->config, $this->wpml_guard, $this->hosts );
		$this->grid_shortcode = new WBC_Grid_Shortcode( $this->grid_data );
	}

	/** @return WBC_WPML_Guard */
	public function get_wpml_guard() {
		return $this->wpml_guard;
	}

	/** @return WBC_Config */
	public function get_config() {
		return $this->config;
	}

	/** @return WBC_Cache_Flush */
	public function get_cache_flush() {
		return $this->cache_flush;
	}

	/** @return WBC_Cost */
	public function get_cost() {
		return $this->cost;
	}

	/** @return WBC_Events */
	public function get_events() {
		return $this->events;
	}

	/** @return WBC_Settings|null */
	public function get_settings() {
		return $this->settings;
	}

	/** @return WBC_Event_Fields|null */
	public function get_event_fields() {
		return $this->event_fields;
	}

	/** @return WBC_Grid_Data */
	public function get_grid_data() {
		return $this->grid_data;
	}

	/** @return WBC_Grid_Shortcode */
	public function get_grid_shortcode() {
		return $this->grid_shortcode;
	}

	private function __clone() {}

	public function __wakeup() {
		throw new \RuntimeException( 'WBC_Plugin is a singleton and cannot be unserialized.' );
	}
}
