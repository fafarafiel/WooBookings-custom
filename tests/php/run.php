<?php
/**
 * Zero-dependency test runner for the pure logic in this plugin.
 *
 * Everything under test here is deliberately free of WordPress: date arithmetic, key formats and
 * the parser that turns vendor availability rules into weekly slots. Those are the parts where a
 * silent off-by-one costs a customer a booking, and they are testable without a WordPress install,
 * so CI stays a few seconds rather than a container build.
 *
 * The classes guard themselves with `defined( 'ABSPATH' ) || exit;`, so the bootstrap defines it.
 * The one exception to "no WordPress" is the event review, which runs against small in-memory
 * doubles defined next to its tests; nothing else in the tested code path calls WordPress.
 *
 * Usage: php tests/php/run.php
 *
 * @package WooBookings_Custom
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../../plugin/includes/class-wbc-grid-data.php';
require_once __DIR__ . '/../../plugin/includes/class-wbc-host-fields.php';
require_once __DIR__ . '/../../plugin/includes/class-wbc-event-review.php';
require_once __DIR__ . '/../../plugin/includes/class-wbc-grid-shortcode.php';

$passed = 0;
$failed = 0;

/**
 * Assert equality and report.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $label    Test name.
 * @return void
 */
function t( $expected, $actual, $label ) {
	global $passed, $failed;
	if ( $expected === $actual ) {
		$passed++;
		echo "  ok   $label\n";
		return;
	}
	$failed++;
	echo "  FAIL $label\n";
	echo '       expected: ' . var_export( $expected, true ) . "\n";
	echo '       actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * Build a stub product exposing only get_availability(), which is all the parser touches.
 *
 * @param array $rules Availability rules.
 * @return object
 */
function stub_product( array $rules ) {
	return new class( $rules ) {
		/** @var array */
		private $rules;

		/**
		 * @param array $rules Availability rules.
		 */
		public function __construct( array $rules ) {
			$this->rules = $rules;
		}

		/**
		 * @return array
		 */
		public function get_availability() {
			return $this->rules;
		}
	};
}

echo "\nhorizon arithmetic\n";

// Native "+N month" overflows the day of month: 31 August plus three months lands on 1 December,
// which would silently extend the horizon into a month the operator never picked. The helper
// clamps an overflow back to the last day of the INTENDED month.
$aug31 = new DateTimeImmutable( '2026-08-31 00:00:00' );
t( '2026-11-30', WBC_Grid_Data::wbc_horizon_end( $aug31, 3 )->format( 'Y-m-d' ), 'month overflow clamps to the last day of the intended month' );

$aug10 = new DateTimeImmutable( '2026-08-10 00:00:00' );
t( '2026-11-10', WBC_Grid_Data::wbc_horizon_end( $aug10, 3 )->format( 'Y-m-d' ), 'ordinary month addition is untouched' );
t( '2027-08-10', WBC_Grid_Data::wbc_horizon_end( $aug10, 12 )->format( 'Y-m-d' ), 'twelve months crosses the year boundary' );
t( '2026-09-10', WBC_Grid_Data::wbc_horizon_end( $aug10, 1 )->format( 'Y-m-d' ), 'one month' );

// A leap day is the other direction of the same trap.
$jan31 = new DateTimeImmutable( '2028-01-31 00:00:00' );
t( '2028-02-29', WBC_Grid_Data::wbc_horizon_end( $jan31, 1 )->format( 'Y-m-d' ), 'January 31 plus one month clamps to the leap day' );

$jan31_common = new DateTimeImmutable( '2027-01-31 00:00:00' );
t( '2027-02-28', WBC_Grid_Data::wbc_horizon_end( $jan31_common, 1 )->format( 'Y-m-d' ), 'same case in a common year' );

echo "\nslot keys\n";

t( '6|18:15', WBC_Host_Fields::slot_key( 6, '18:15' ), 'weekday and time compose into the map key' );
t( '1|09:00', WBC_Host_Fields::slot_key( '1', '09:00' ), 'numeric string weekday is normalised' );

echo "\nweekly slot extraction\n";

$fields = new ReflectionClass( 'WBC_Host_Fields' );
$parser = $fields->newInstanceWithoutConstructor();

$slots = $parser->get_weekly_slots(
	stub_product(
		array(
			array(
				'type'     => 'time:4',
				'bookable' => 'yes',
				'from'     => '16:00',
				'to'       => '18:00',
			),
			array(
				'type'     => 'time:1',
				'bookable' => 'yes',
				'from'     => '18:15',
				'to'       => '20:15',
			),
			array(
				'type'     => 'time:6',
				'bookable' => 'yes',
				'from'     => '16:00',
				'to'       => '18:00',
			),
		)
	)
);
t( 3, count( $slots ), 'three enabling rules yield three slots' );
t( '1|18:15', $slots[0]['key'], 'slots come back sorted by weekday' );
t( '4|16:00', $slots[1]['key'], 'then Thursday' );
t( '6|16:00', $slots[2]['key'], 'then Saturday' );

// A disabling rule is a gap in the schedule, not a slot anybody can be assigned to.
$slots = $parser->get_weekly_slots(
	stub_product(
		array(
			array(
				'type'     => 'time:2',
				'bookable' => 'yes',
				'from'     => '16:00',
				'to'       => '18:00',
			),
			array(
				'type'     => 'time:3',
				'bookable' => 'no',
				'from'     => '16:00',
				'to'       => '18:00',
			),
		)
	)
);
t( 1, count( $slots ), 'a non-bookable rule is not a slot' );
t( '2|16:00', $slots[0]['key'], 'only the enabling rule survives' );

// Date-range and custom rules share the array with weekly ones and must be ignored here.
$slots = $parser->get_weekly_slots(
	stub_product(
		array(
			array(
				'type'     => 'custom',
				'bookable' => 'yes',
				'from'     => '2026-08-15',
				'to'       => '2026-08-15',
			),
			array(
				'type'     => 'months',
				'bookable' => 'yes',
				'from'     => '1',
				'to'       => '3',
			),
			array(
				'type'     => 'time:5',
				'bookable' => 'yes',
				'from'     => '20:30',
				'to'       => '22:30',
			),
		)
	)
);
t( 1, count( $slots ), 'only time:N rules are weekly slots' );
t( '5|20:30', $slots[0]['key'], 'the weekly rule is the one that survives' );

// Two rules for the same weekday and time are one slot, not two rows in the editor.
$slots = $parser->get_weekly_slots(
	stub_product(
		array(
			array(
				'type'     => 'time:3',
				'bookable' => 'yes',
				'from'     => '16:00',
				'to'       => '18:00',
			),
			array(
				'type'     => 'time:3',
				'bookable' => 'yes',
				'from'     => '16:00',
				'to'       => '17:00',
			),
		)
	)
);
t( 1, count( $slots ), 'duplicate weekday and start time collapse to one slot' );

// Malformed input must be dropped rather than producing a broken key.
$slots = $parser->get_weekly_slots(
	stub_product(
		array(
			array(
				'type'     => 'time:9',
				'bookable' => 'yes',
				'from'     => '16:00',
			),
			array(
				'type'     => 'time:2',
				'bookable' => 'yes',
				'from'     => 'nonsense',
			),
			array( 'type' => 'time:2' ),
			'not-an-array',
		)
	)
);
t( 0, count( $slots ), 'out-of-range weekday, bad time and malformed rules are all dropped' );

t( array(), $parser->get_weekly_slots( stub_product( array() ) ), 'no rules means no slots' );
t( array(), $parser->get_weekly_slots( null ), 'a missing product does not fatal' );

// Review of capped products without the Special event marker (1.0.0 events).
t( true, WBC_Event_Review::needs_review( 'ceremony', 10, '' ), 'a capped session without the marker is listed' );
t( false, WBC_Event_Review::needs_review( 'ceremony', 10, '1' ), 'a marked event is not listed' );
t( false, WBC_Event_Review::needs_review( 'ceremony', 0, '' ), 'a session without a cap is not listed' );
t( false, WBC_Event_Review::needs_review( 'flex', 10, '' ), 'open entry is never listed' );

/*
 * pending_ids() and apply() against in-memory doubles for the few WordPress functions and
 * collaborators they touch. The real classes are not loaded by this runner.
 */
$GLOBALS['wbc_options'] = array();
$GLOBALS['wbc_meta']    = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wbc_options'] ) ? $GLOBALS['wbc_options'][ $name ] : $default;
}
function update_option( $name, $value ) {
	$GLOBALS['wbc_options'][ $name ] = $value;
	return true;
}
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['wbc_meta'][ $id ][ $key ] ) ? $GLOBALS['wbc_meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['wbc_meta'][ $id ][ $key ] = $value;
	return true;
}
final class WBC_Config {
	const META_IS_EVENT       = '_woobookings_custom_is_event';
	const META_EVENT_CAPACITY = '_woobookings_custom_event_capacity';
	public $products = array();
	public $types    = array();
	public function get_product_ids() {
		return $this->products;
	}
	public function get_product_type( $id ) {
		return isset( $this->types[ $id ] ) ? $this->types[ $id ] : 'ceremony';
	}
	public function get_event_capacity( $id ) {
		return (int) get_post_meta( $id, self::META_EVENT_CAPACITY, true );
	}
}
final class WBC_Cache_Flush {
	public $calls = 0;
	public function flush_for_booking( $booking_id = 0 ) {
		$this->calls++;
	}
}

/**
 * Fresh review fixture: 10 capped session, 11 uncapped session, 12 capped open entry,
 * 13 marked event.
 *
 * @return array{0: WBC_Event_Review, 1: WBC_Cache_Flush}
 */
function wbc_review_fixture() {
	$cap                    = WBC_Config::META_EVENT_CAPACITY;
	$GLOBALS['wbc_options'] = array();
	$GLOBALS['wbc_meta']    = array(
		10 => array( $cap => '10' ),
		11 => array( $cap => '0' ),
		12 => array( $cap => '6' ),
		13 => array( $cap => '8', WBC_Config::META_IS_EVENT => '1' ),
	);
	$config           = new WBC_Config();
	$config->products = array( 10, 11, 12, 13 );
	$config->types    = array( 12 => 'flex' );
	$flush            = new WBC_Cache_Flush();
	return array( new WBC_Event_Review( $config, $flush ), $flush );
}

list( $review, $flush ) = wbc_review_fixture();
t( array( 10 ), $review->pending_ids(), 'review: only the capped session without the marker is listed' );
t( 1, $review->apply( 'mark', array( 10 ) ), 'review: marking marks the listed product' );
t( '1', get_post_meta( 10, WBC_Config::META_IS_EVENT, true ), 'review: the listed product now carries the marker' );
t( '', get_post_meta( 12, WBC_Config::META_IS_EVENT, true ), 'review: open entry is left alone' );
t( 1, $flush->calls, 'review: the slots cache is flushed once' );
t( array(), $review->pending_ids(), 'review: the notice does not come back after an answer' );

list( $review, $flush ) = wbc_review_fixture();
t( 0, $review->apply( 'keep' ), 'review: keeping them as sessions marks nothing' );
t( '', get_post_meta( 10, WBC_Config::META_IS_EVENT, true ), 'review: the capped session stays a regular session' );
t( 0, $flush->calls, 'review: nothing marked, nothing flushed' );
t( array(), $review->pending_ids(), 'review: the answer is recorded' );

list( $review, $flush ) = wbc_review_fixture();
t( 0, $review->apply( 'other' ), 'review: an unknown answer does nothing' );
t( array( 10 ), $review->pending_ids(), 'review: and the notice stays' );

list( $review, $flush ) = wbc_review_fixture();
$GLOBALS['wbc_meta'][10][ WBC_Config::META_IS_EVENT ] = '1';
t( 0, $review->apply( 'mark', array( 10 ) ), 'review: with nothing listed, marking marks nothing' );
t( 0, $flush->calls, 'review: and does not flush' );

// A product capped after the notice was rendered is not marked unseen.
list( $review, $flush ) = wbc_review_fixture();
$GLOBALS['wbc_meta'][11][ WBC_Config::META_EVENT_CAPACITY ] = '4';
t( 1, $review->apply( 'mark', array( 10 ) ), 'review: only products shown in the notice are marked' );
t( '', get_post_meta( 11, WBC_Config::META_IS_EVENT, true ), 'review: a product that was not shown stays as it is' );
t( 0, ( function () {
	list( $review ) = wbc_review_fixture();
	return $review->apply( 'mark', array( 13, 12, 999 ) );
} )(), 'review: shown IDs that do not match the rule are ignored' );

/*
 * notice() and handle(): the WordPress functions they call, as doubles that record what happened.
 */
define( 'WBC_TEST_CAP', 'manage_woocommerce' );
final class WBC_Settings {
	const CAPABILITY = WBC_TEST_CAP;
}
final class WBC_Test_Stop extends Exception {
}
$GLOBALS['wbc_can']      = true;
$GLOBALS['wbc_titles']   = array();
$GLOBALS['wbc_nonce']    = array();
$GLOBALS['wbc_redirect'] = null;
function current_user_can( $cap ) {
	return WBC_TEST_CAP === $cap && $GLOBALS['wbc_can'];
}
function get_the_title( $id ) {
	return isset( $GLOBALS['wbc_titles'][ $id ] ) ? $GLOBALS['wbc_titles'][ $id ] : 'Product ' . $id;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return (string) $url;
}
function esc_html__( $text, $domain = '' ) {
	return esc_html( $text );
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}
function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr( $action ) . '" />';
}
$GLOBALS['wbc_nonce_ok'] = true;
function check_admin_referer( $action ) {
	$GLOBALS['wbc_nonce'][] = $action;
	if ( ! $GLOBALS['wbc_nonce_ok'] ) {
		throw new WBC_Test_Stop( 'bad nonce' );
	}
	return true;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_unslash( $value ) {
	return $value;
}
function absint( $value ) {
	return abs( (int) $value );
}
function wp_get_referer() {
	return 'https://example.test/wp-admin/edit.php?post_type=product';
}
function wp_safe_redirect( $location ) {
	$GLOBALS['wbc_redirect'] = $location;
	throw new WBC_Test_Stop( 'redirect' );
}
function wp_die( $message ) {
	throw new WBC_Test_Stop( 'die' );
}

$GLOBALS['wbc_hooks'] = array();
function add_action( $hook, $callback ) {
	$GLOBALS['wbc_hooks'][] = $hook;
}
list( $review ) = wbc_review_fixture();
$review->register();
t( array( 'admin_notices', 'admin_post_wbc_review_events' ), $GLOBALS['wbc_hooks'], 'register: the notice and the logged-in handler, no nopriv handler' );
$GLOBALS['wbc_titles'] = array( 10 => 'Evening <b>session</b>' );
ob_start();
$review->notice();
$html = ob_get_clean();
t( true, false !== strpos( $html, 'Evening &lt;b&gt;session&lt;/b&gt;' ), 'notice: product titles are escaped' );
t( true, false !== strpos( $html, 'name="ids[]" value="10"' ), 'notice: the listed IDs travel with the answer' );
t( false, false !== strpos( $html, 'button-primary' ), 'notice: neither answer is the default button' );
t( true, false !== strpos( $html, 'notice-error' ), 'notice: shown as an error, because events do not block until answered' );
t( 1, preg_match( '#<form method="post" action="https://example\.test/wp-admin/admin-post\.php">#', $html ), 'notice: the form posts to admin-post.php' );
t( true, false !== strpos( $html, '<input type="hidden" name="action" value="wbc_review_events" />' ), 'notice: the form names the review action' );
t( true, false !== strpos( $html, 'name="_wpnonce" value="nonce-wbc_review_events"' ), 'notice: the form carries the nonce' );
t( 1, preg_match( '#value="mark">Mark them as special events</button>#', $html ), 'notice: the mark label is on the mark button' );
t( 1, preg_match( '#value="keep">They are regular sessions</button>#', $html ), 'notice: the keep label is on the keep button' );
$GLOBALS['wbc_can'] = false;
ob_start();
$review->notice();
t( '', ob_get_clean(), 'notice: hidden from users who cannot manage the shop' );

list( $review ) = wbc_review_fixture();
$_POST = array( 'choice' => 'mark', 'ids' => array( '10' ) );
$stop  = '';
try {
	$review->handle();
} catch ( WBC_Test_Stop $e ) {
	$stop = $e->getMessage();
}
t( 'die', $stop, 'handle: refused without the capability' );
t( '', get_post_meta( 10, WBC_Config::META_IS_EVENT, true ), 'handle: and nothing is marked' );

$GLOBALS['wbc_can']   = true;
$GLOBALS['wbc_nonce'] = array();
try {
	$review->handle();
} catch ( WBC_Test_Stop $e ) {
	$stop = $e->getMessage();
}
t( array( WBC_Event_Review::ACTION ), $GLOBALS['wbc_nonce'], 'handle: the nonce is checked' );
t( '1', get_post_meta( 10, WBC_Config::META_IS_EVENT, true ), 'handle: the answer is applied' );
t( 'redirect', $stop, 'handle: then redirects' );
t( 'https://example.test/wp-admin/edit.php?post_type=product', $GLOBALS['wbc_redirect'], 'handle: back to the page the answer came from' );
$_POST = array();

// A bad nonce stops the request before anything is changed.
list( $review )             = wbc_review_fixture();
$_POST                      = array( 'choice' => 'mark', 'ids' => array( '10' ) );
$GLOBALS['wbc_nonce_ok']    = false;
$stop                       = '';
try {
	$review->handle();
} catch ( WBC_Test_Stop $e ) {
	$stop = $e->getMessage();
}
t( 'bad nonce', $stop, 'handle: a bad nonce stops the request' );
t( array( '', false ), array( get_post_meta( 10, WBC_Config::META_IS_EVENT, true ), get_option( WBC_Event_Review::OPTION_DONE, false ) ), 'handle: and nothing is marked or recorded' );
$GLOBALS['wbc_nonce_ok'] = true;
$_POST                   = array();

// Both shortcode names render the grid: [wbc_grid] and the 1.0.0 name.
$GLOBALS['wbc_shortcodes'] = array();
function add_shortcode( $tag, $callback ) {
	$GLOBALS['wbc_shortcodes'][ $tag ] = $callback;
}
$shortcode = new WBC_Grid_Shortcode( ( new ReflectionClass( 'WBC_Grid_Data' ) )->newInstanceWithoutConstructor() );
t( array( 'wbc_grid', 'wbc_grid_rezerwacji' ), array_keys( $GLOBALS['wbc_shortcodes'] ), 'shortcode: registered under the new and the 1.0.0 name' );
t( array( array( $shortcode, 'render' ), array( $shortcode, 'render' ) ), array_values( $GLOBALS['wbc_shortcodes'] ), 'shortcode: both names render the grid' );

echo "\n" . str_repeat( '-', 46 ) . "\n";
echo "passed: $passed   failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
