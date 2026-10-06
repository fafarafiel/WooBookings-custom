<?php
/**
 * Plugin Name:       WooBookings Custom
 * Description:       Companion plugin for WooCommerce Bookings. Renders a day-strip booking grid, adds special events with their own capacity and whole-evening exclusivity, assigns a host per recurring slot, shows product and host images in the session details, disarms WPML booking duplication and flushes vendor caches on the paths the vendor misses. Touches no vendor file.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            iD4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woobookings-custom
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce, woocommerce-bookings
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

define( 'WOOBOOKINGS_CUSTOM_VERSION', '1.1.0' );
define( 'WOOBOOKINGS_CUSTOM_FILE', __FILE__ );
define( 'WOOBOOKINGS_CUSTOM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOOBOOKINGS_CUSTOM_URL', plugin_dir_url( __FILE__ ) );
define( 'WOOBOOKINGS_CUSTOM_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Activation hook.
 *
 * Bookings runs its schema migrations only from its own activation hook. A file-level deploy
 * that swaps plugin files without reactivating the vendor plugin skips those migrations and
 * fails later in ways that look unrelated, so we nudge the install check explicitly.
 *
 * WC_Bookings_Install::install() is a PRIVATE static, which means an is_callable() guard on it
 * is always false and the branch never runs. The public entry points are maybe_install() and
 * maybe_update(); both self-guard on the stored schema version and are safe to call every time.
 */
function woobookings_custom_activate() {
	if ( class_exists( 'WC_Bookings_Install' ) ) {
		if ( is_callable( array( 'WC_Bookings_Install', 'maybe_install' ) ) ) {
			WC_Bookings_Install::maybe_install();
		}
		if ( is_callable( array( 'WC_Bookings_Install', 'maybe_update' ) ) ) {
			WC_Bookings_Install::maybe_update();
		}
	}
	if ( class_exists( 'WC_Bookings_Cache' ) && is_callable( array( 'WC_Bookings_Cache', 'clear_cache' ) ) ) {
		WC_Bookings_Cache::clear_cache();
	}
}
register_activation_hook( __FILE__, 'woobookings_custom_activate' );

/**
 * Load translations.
 */
function woobookings_custom_load_textdomain() {
	load_plugin_textdomain(
		'woobookings-custom',
		false,
		dirname( WOOBOOKINGS_CUSTOM_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'woobookings_custom_load_textdomain' );

/**
 * Admin notice shown when a hard dependency is missing.
 */
function woobookings_custom_missing_deps_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'WooBookings Custom requires WooCommerce and WooCommerce Bookings to be active.', 'woobookings-custom' );
	echo '</p></div>';
}

/**
 * Bootstrap.
 *
 * Priority 20 on plugins_loaded is load-bearing: WooCommerce Multilingual registers its booking
 * duplication hook at the default priority, and the WPML guard removes that callback. Running
 * earlier would call remove_action() against a callback that does not exist yet, which fails
 * silently and leaves the duplication in place.
 */
function woobookings_custom_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Bookings' ) ) {
		add_action( 'admin_notices', 'woobookings_custom_missing_deps_notice' );
		return;
	}

	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-wpml-guard.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-config.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-cache-flush.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-cost.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-settings.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-event-fields.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-image.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-hosts.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-host-fields.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-grid-data.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-grid-shortcode.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-events.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-hold.php';
	require_once WOOBOOKINGS_CUSTOM_PATH . 'includes/class-wbc-booking-plugin.php';

	WBC_Plugin::instance();
}
add_action( 'plugins_loaded', 'woobookings_custom_bootstrap', 20 );
