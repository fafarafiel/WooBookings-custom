<?php
/**
 * Cost read layer: prices per person type for display on the grid card.
 *
 * Bookings stays the only place that COMPUTES a booking's cost; this class never hooks the cost
 * filter. It reads the same inputs the engine sums (product, resource and person-type costs) and
 * reproduces the per-person figure for the card, or declines (null) whenever the engine would
 * apply something the card cannot mirror. Rule: the card never shows a price it cannot compute
 * the way the engine does.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Formula source: WC_Bookings_Cost_Calculation::calculate_booking_cost()
 * (class-wc-bookings-cost-calculation.php:50-76, 128-135, 293-315 in Bookings 3.7.0).
 */
final class WBC_Cost {

	/**
	 * Nothing to hook: pricing belongs to Bookings, this class only reads it. It stopped being an
	 * inert choke-point and became a read layer.
	 *
	 * @return void
	 */
	public function register() {}

	/**
	 * Person types of a product with the per-person price the cart will charge.
	 *
	 * Empty array when the product has no person types (the grid keeps its single counter).
	 * unitPrice is null when the card cannot state an honest per-person price — the counters are
	 * still rendered, only the amount is omitted.
	 *
	 * @param WC_Product|false|null $product Product that will receive the add-to-cart (translation).
	 * @return array<int,array{id:int,field:string,label:string,min:int,max:int|null,unitPrice:float|null,unitPriceText:string}>
	 */
	public static function person_type_prices( $product ) {
		if ( ! self::uses_person_types( $product ) ) {
			return array();
		}

		$types = $product->get_person_types();
		if ( ! is_array( $types ) || empty( $types ) ) {
			return array();
		}

		$shared = self::shared_unit_part( $product );
		$blocks = self::blocks_for_display( $product );
		$out    = array();

		foreach ( $types as $type ) {
			if ( ! is_object( $type ) || ! method_exists( $type, 'get_id' ) ) {
				continue;
			}

			// The engine adds a person-type cost only when it is > 0 (cost-calculation.php:67,75).
			$type_base  = max( 0.0, (float) $type->get_cost() );
			$type_block = max( 0.0, (float) $type->get_block_cost() );

			$unit = null;
			if ( null !== $shared && ( null !== $blocks || 0.0 === $type_block ) ) {
				$unit = $shared + $type_base + $type_block * (int) $blocks;
			}

			$min = $type->get_min();
			$max = $type->get_max();

			$out[] = array(
				'id'            => (int) $type->get_id(),
				'field'         => 'wc_bookings_field_persons_' . (int) $type->get_id(),
				'label'         => (string) $type->get_name(),
				'min'           => is_numeric( $min ) ? max( 0, (int) $min ) : 0,
				// Any numeric max is enforced by the vendor, 0 included (class-wc-product-booking.php:2795).
				'max'           => is_numeric( $max ) ? max( 0, (int) $max ) : null,
				'unitPrice'     => null === $unit ? null : self::display_amount( $product, $unit ),
				'unitPriceText' => null === $unit ? '' : self::format_amount( $product, $unit ),
			);
		}

		return $out;
	}

	/**
	 * Whether the cart reads per-type persons. Bookings reads and validates persons only when
	 * "Has persons" is on (wc-bookings-functions.php:1576, class-wc-product-booking.php:2777);
	 * with types ticked but persons off the type fields are ignored and the booking costs the
	 * product price — showing type prices then would be a price the engine never charges.
	 *
	 * @param WC_Product|false|null $product
	 * @return bool
	 */
	public static function uses_person_types( $product ) {
		return is_object( $product )
			&& method_exists( $product, 'has_persons' ) && $product->has_persons()
			&& method_exists( $product, 'has_person_types' ) && $product->has_person_types();
	}

	/**
	 * Per-person share of the product/resource costs, or null when it cannot be stated per person.
	 *
	 * With the person cost multiplier the engine multiplies (base + blocks + resource) by the head
	 * count, so that sum IS a per-person amount. Without the multiplier it is charged once per
	 * booking — only a zero sum leaves the per-person price exact.
	 *
	 * @param WC_Product $product
	 * @return float|null
	 */
	private static function shared_unit_part( $product ) {
		// Cost rules (incl. "persons" ranges) rewrite the block/base cost before the multiplier
		// (cost-calculation.php:144-283); a display price replaces the shop figure. Either way the
		// card would show a number the cart does not charge.
		if ( method_exists( $product, 'get_costs' ) && ! empty( $product->get_costs() ) ) {
			return null;
		}
		if ( method_exists( $product, 'get_display_cost' ) && '' !== trim( (string) $product->get_display_cost() ) ) {
			return null;
		}

		$resource = self::resource_costs( $product );
		if ( null === $resource ) {
			return null;
		}

		$base  = max( 0.0, (float) $product->get_cost() ) + $resource['base'];
		$block = max( 0.0, (float) $product->get_block_cost() ) + $resource['block'];

		$blocks = self::blocks_for_display( $product );
		if ( null === $blocks && $block > 0 ) {
			return null;
		}
		// The engine clamps the booking total at 0 (cost-calculation.php:309).
		$shared = max( 0.0, $base + $block * (int) $blocks );

		if ( method_exists( $product, 'get_has_person_cost_multiplier' ) && $product->get_has_person_cost_multiplier() ) {
			return $shared;
		}

		return $shared > 0 ? null : 0.0;
	}

	/**
	 * Booked block count the card can assume: 1 for fixed-duration products (the engine books
	 * ceil(duration / block) = 1 block). For customer-chosen durations the count is the
	 * visitor's choice, so there is no single figure — null.
	 *
	 * @param WC_Product $product
	 * @return int|null
	 */
	private static function blocks_for_display( $product ) {
		if ( method_exists( $product, 'is_duration_type' ) && $product->is_duration_type( 'fixed' ) ) {
			return 1;
		}
		return null;
	}

	/**
	 * Base and block cost of the resource the engine will assign. With automatic assignment
	 * the cart may land on any published resource of the product, so differing costs across
	 * resources make the price unknowable up front (null).
	 *
	 * @param WC_Product $product
	 * @return array{base:float,block:float}|null
	 */
	private static function resource_costs( $product ) {
		if ( ! method_exists( $product, 'has_resources' ) || ! $product->has_resources() || ! method_exists( $product, 'get_resources' ) ) {
			return array(
				'base'  => 0.0,
				'block' => 0.0,
			);
		}

		$costs = null;
		foreach ( (array) $product->get_resources() as $resource ) {
			if ( ! is_object( $resource ) ) {
				continue;
			}
			// Added unclamped, exactly as the engine does (cost-calculation.php:293-307).
			$current = array(
				'base'  => (float) $resource->get_base_cost(),
				'block' => (float) $resource->get_block_cost(),
			);
			if ( null !== $costs && $costs !== $current ) {
				return null;
			}
			$costs = $current;
		}

		return null === $costs ? array(
			'base'  => 0.0,
			'block' => 0.0,
		) : $costs;
	}

	/**
	 * Amount as the shop displays it (tax display setting applied).
	 *
	 * @param WC_Product $product
	 * @param float      $amount
	 * @return float
	 */
	private static function display_amount( $product, $amount ) {
		if ( function_exists( 'wc_get_price_to_display' ) ) {
			return (float) wc_get_price_to_display(
				$product,
				array(
					'price' => $amount,
					'qty'   => 1,
				)
			);
		}
		return (float) $amount;
	}

	/**
	 * Plain-text formatted amount (for example "50.00") for insertion via textContent.
	 *
	 * @param WC_Product $product
	 * @param float      $amount
	 * @return string
	 */
	private static function format_amount( $product, $amount ) {
		$display = self::display_amount( $product, $amount );
		if ( ! function_exists( 'wc_price' ) ) {
			return (string) $display;
		}
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $display ) ), ENT_QUOTES, 'UTF-8' ) );
	}
}
