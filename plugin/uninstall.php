<?php
/**
 * Uninstall: remove the companion's own options and its dated host exception meta. Never touches
 * Bookings/WCML data.
 *
 * @package WooBookings_Custom
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$woobookings_custom_options = array(
	'woobookings_custom_resource_id',
	'woobookings_custom_product_ids',
	'woobookings_custom_flex_product_id',
	'woobookings_custom_ceremony_product_id',
	'woobookings_custom_events_reviewed',
	'woobookings_custom_horizon_months',
	// Legacy keys from earlier versions; kept so uninstall cleans up after an upgrade path.
	'woobookings_custom_host_avatar_meta',
	'woobookings_custom_host_bio_meta',
);

foreach ( $woobookings_custom_options as $woobookings_custom_option ) {
	delete_option( $woobookings_custom_option );
}

// Dated host exceptions: data of this plugin only, and past entries are useless anyway. Nothing
// that belongs to Bookings or WCML is touched.
delete_post_meta_by_key( '_woobookings_custom_host_exceptions' );
