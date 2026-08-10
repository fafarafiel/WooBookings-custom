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
 * Nothing in the tested code path calls a WordPress function.
 *
 * Usage: php tests/php/run.php
 *
 * @package WooBookings_Custom
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../../plugin/includes/class-wbc-grid-data.php';
require_once __DIR__ . '/../../plugin/includes/class-wbc-host-fields.php';

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

echo "\n" . str_repeat( '-', 46 ) . "\n";
echo "passed: $passed   failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
