<?php
/**
 * Config. Resolves the shared resource and the bookable products bound to it from options
 * plus discovery. No hardcoded product IDs: staging IDs differ from production, so product IDs
 * are always resolved from options/discovery, never written as literals in code.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the resource anchor from an option and discovers the flex + ceremony products sharing it.
 * All returned IDs are canonical PL. Getters are memoized (cheap on the hot path).
 */
final class WBC_Config {

	const OPTION_RESOURCE    = 'woobookings_custom_resource_id';
	const OPTION_PRODUCT_IDS = 'woobookings_custom_product_ids';
	const OPTION_FLEX        = 'woobookings_custom_flex_product_id';
	const OPTION_CEREMONY    = 'woobookings_custom_ceremony_product_id';
	const OPTION_HORIZON     = 'woobookings_custom_horizon_months';

	const HORIZON_DEFAULT = 3;
	const HORIZON_MIN     = 1;
	const HORIZON_MAX     = 12;

	/*
	 * Postmeta marking a bookable product as a special event with its own seat cap (e.g. 12 vs the
	 * standard ceremony's 18). Its PRESENCE with a positive value means "this is an event"; its
	 * value is the single source of the capacity cap read by both runtime filters (slice 3, Faza 2).
	 * Not a product ID and not a taxonomy term (a category would be WPML-translated and break the
	 * marker under EN/DE) — a hidden postmeta (leading underscore) travels on the canonical product.
	 */
	const META_EVENT_CAPACITY = '_woobookings_custom_event_capacity';

	/**
	 * Session host. Both meta keys live on the CANONICAL product, exactly like event capacity:
	 * a value written on a translation is invisible to the runtime, which is a silent failure
	 * mode rather than an error.
	 *
	 * DEFAULT is one person for every slot of the product, so the common case costs one choice.
	 * MAP holds per-slot overrides keyed `ISOWeekday|HH:MM`, for example `6|18:15`, with the
	 * host post ID as the value.
	 *
	 * The key is a weekday, not a date. A recurring schedule has no end date, so a date-keyed
	 * map would grow without bound and would need topping up every week.
	 */
	const META_HOST_DEFAULT = '_woobookings_custom_host_default';
	const META_HOST_MAP     = '_woobookings_custom_host_map';

	/*
	 * No resource default. A staging ID as fallback (this used to be 848) fails silently on any
	 * other install: the resource does not exist, discovery returns [], the grid renders empty and
	 * WBC_Cache_Flush iterates nothing — all without a single log entry. Unconfigured must be
	 * loud (see WBC_Settings::unconfigured_notice), never a plausible-looking wrong answer.
	 */
	const DEFAULT_RESOURCE = 0;

	/** @var WBC_WPML_Guard */
	private $wpml_guard;

	/** @var int[]|null */
	private $product_ids = null;

	/** @var array<int,string>|null id => 'flex'|'ceremony' */
	private $types = null;

	/** @var array<int,int> canonical id => event capacity (0 = not an event); per-request memo */
	private $event_caps = array();

	/**
	 * @param WBC_WPML_Guard $wpml_guard
	 */
	public function __construct( WBC_WPML_Guard $wpml_guard ) {
		$this->wpml_guard = $wpml_guard;

		add_action( 'update_option_' . self::OPTION_RESOURCE, array( $this, 'invalidate_products_cache' ) );
		add_action( 'add_option_' . self::OPTION_RESOURCE, array( $this, 'invalidate_products_cache' ) );
		add_action( 'save_post_product', array( $this, 'invalidate_products_cache' ) );
		add_action( 'save_post_bookable_resource', array( $this, 'invalidate_products_cache' ) );
	}

	/**
	 * Shared resource ID (canonical anchor).
	 *
	 * @return int
	 */
	public function get_resource_id() {
		return (int) get_option( self::OPTION_RESOURCE, self::DEFAULT_RESOURCE );
	}

	/**
	 * Booking-navigation horizon in months. Clamped HERE, not only in the settings sanitizer:
	 * a value written straight to the DB bypasses sanitize_callback, and a horizon shorter than
	 * one grid page would degenerate the page partition. Absent option = default; a stored 0
	 * (empty field saved) clamps to the minimum rather than silently jumping to the default.
	 *
	 * @return int
	 */
	public function get_horizon_months() {
		$raw = get_option( self::OPTION_HORIZON, null );
		if ( null === $raw || '' === $raw || false === $raw ) {
			return self::HORIZON_DEFAULT;
		}
		return min( self::HORIZON_MAX, max( self::HORIZON_MIN, (int) $raw ) );
	}

	/**
	 * Whether the companion can actually resolve what it needs: a resource anchor and at least one
	 * bookable product on it. False means the grid would render empty and the cache flush would be
	 * a no-op — a state that must surface in wp-admin instead of passing for "no slots today".
	 *
	 * @return bool
	 */
	public function is_configured() {
		return $this->get_resource_id() > 0 && ! empty( $this->get_product_ids() );
	}

	/**
	 * Canonical PL IDs of the bookable products on the resource. Option first; on miss, discover
	 * by resource and cache the result.
	 *
	 * @return int[]
	 */
	public function get_product_ids() {
		if ( null !== $this->product_ids ) {
			return $this->product_ids;
		}

		$resource = $this->get_resource_id();
		$stored   = get_option( self::OPTION_PRODUCT_IDS, array() );

		/*
		 * The cache is stamped with the resource it was discovered for. Without that stamp this
		 * option is a cache with no invalidation, and the cutover sequence walks straight into it:
		 * set resource_id BEFORE creating the ceremony product and discovery freezes [320] forever
		 * is_configured() would then report true, the misconfiguration notice would go quiet, and the grid would ship
		 * without ceremonies. A stale stamp must lose to a re-discovery, never win.
		 */
		if ( isset( $stored['resource'], $stored['ids'] ) && (int) $stored['resource'] === $resource && is_array( $stored['ids'] ) ) {
			$this->product_ids = array_values( array_unique( array_map( 'intval', $stored['ids'] ) ) );
			return $this->product_ids;
		}

		$discovered = $this->discover_products_by_resource( $resource );
		if ( ! empty( $discovered ) ) {
			update_option( self::OPTION_PRODUCT_IDS, array( 'resource' => $resource, 'ids' => $discovered ), false );
		} else {
			// Never leave a stamp behind for a resource that resolved to nothing.
			delete_option( self::OPTION_PRODUCT_IDS );
		}

		$this->product_ids = $discovered;
		return $this->product_ids;
	}

	/**
	 * Drop the discovery cache. Bound to every event that can change what discovery would return:
	 * the anchor itself, and any product/resource edit that re-wires the resource↔product mapping.
	 *
	 * @return void
	 */
	public function invalidate_products_cache() {
		delete_option( self::OPTION_PRODUCT_IDS );
		$this->product_ids = null;
		$this->types       = null;
		$this->event_caps  = array();
	}

	/**
	 * Flex (customer-defined duration) product ID. Option override wins; else classified.
	 *
	 * @return int
	 */
	public function get_flex_product_id() {
		$override = (int) get_option( self::OPTION_FLEX, 0 );
		if ( $override ) {
			return $this->wpml_guard->wbc_canonical_id( $override );
		}
		return $this->first_of_type( 'flex' );
	}

	/**
	 * Ceremony (fixed duration, per-person cost) product ID. Option override wins; else classified.
	 *
	 * @return int
	 */
	public function get_ceremony_product_id() {
		$override = (int) get_option( self::OPTION_CEREMONY, 0 );
		if ( $override ) {
			return $this->wpml_guard->wbc_canonical_id( $override );
		}
		return $this->first_of_type( 'ceremony' );
	}

	/**
	 * Type of a product on the resource.
	 *
	 * @param int $id Canonical product ID.
	 * @return string 'flex'|'ceremony'
	 */
	public function get_product_type( $id ) {
		$types = $this->classify_products();
		$id    = (int) $id;
		return isset( $types[ $id ] ) ? $types[ $id ] : 'ceremony';
	}

	/**
	 * Event seat cap for a product, or 0 when it is not an event. Always resolved on the CANONICAL
	 * PL product: the runtime filters (Faza 2) receive the translated product on EN/DE checkout, and
	 * WPML need not copy this meta to the translation, so reading the raw passed ID would lose the
	 * cap under EN/DE. Canonicalizing here makes every caller safe regardless of what it passes.
	 * Memoized per canonical ID (cheap on the hot path).
	 *
	 * @param int $id Product ID (any language).
	 * @return int Positive capacity for an event; 0 otherwise.
	 */
	public function get_event_capacity( $id ) {
		$canonical = $this->wpml_guard->wbc_canonical_id( (int) $id );
		if ( ! $canonical ) {
			return 0;
		}
		if ( ! isset( $this->event_caps[ $canonical ] ) ) {
			$this->event_caps[ $canonical ] = max( 0, (int) get_post_meta( $canonical, self::META_EVENT_CAPACITY, true ) );
		}
		return $this->event_caps[ $canonical ];
	}

	/**
	 * Whether a product is a special event (has a positive event capacity meta on its canonical PL).
	 *
	 * @param int $id Product ID (any language).
	 * @return bool
	 */
	public function is_event( $id ) {
		return $this->get_event_capacity( $id ) > 0;
	}

	/**
	 * Discover bookable products whose resource set contains the anchor resource. Uses only the
	 * product API (no raw SQL). Returns canonical PL IDs.
	 *
	 * @param int $resource_id
	 * @return int[]
	 */
	public function discover_products_by_resource( $resource_id ) {
		$found       = array();
		$resource_id = (int) $resource_id;

		if ( ! $resource_id || ! function_exists( 'wc_get_product' ) ) {
			return $found;
		}

		/*
		 * Language-agnostic query, mirroring the vendor's own booking-products lookup
		 * (class-wc-product-booking-data-store-cpt.php:335-358): get_posts with
		 * suppress_filters => true so WPML does not scope the result set to the current request
		 * language. wc_get_products() (the old call here) IS WPML-scoped — under EN/DE it returns
		 * only translated products, so an event that exists in the source language alone
		 * would never be discovered on the EN/DE grid (kryterium 5). Product types come from the
		 * vendor helper so an accommodation-booking add-on would be covered too; the object guard
		 * below still narrows to real WC_Product_Booking instances.
		 */
		$booking_types = function_exists( 'wc_bookings_get_product_types' )
			? (array) wc_bookings_get_product_types()
			: array( 'booking' );

		$tax_query = array( 'relation' => 'OR' );
		foreach ( $booking_types as $booking_type ) {
			$tax_query[] = array(
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => $booking_type,
			);
		}

		$product_ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'tax_query'        => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			)
		);

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product_Booking' ) ) {
				continue;
			}
			if ( ! method_exists( $product, 'get_resource_ids' ) ) {
				continue;
			}
			$resource_ids = array_map( 'intval', (array) $product->get_resource_ids() );
			if ( ! in_array( $resource_id, $resource_ids, true ) ) {
				continue;
			}
			$found[] = $this->wpml_guard->wbc_canonical_id( $product->get_id() );
		}

		return array_values( array_unique( array_map( 'intval', $found ) ) );
	}

	/**
	 * Build id => type map. Flex = customer-defined duration; everything else on the resource is
	 * treated as ceremony.
	 *
	 * @return array<int,string>
	 */
	private function classify_products() {
		if ( null !== $this->types ) {
			return $this->types;
		}

		$this->types = array();
		foreach ( $this->get_product_ids() as $id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! $product || ! is_object( $product ) ) {
				continue;
			}
			$duration_type = method_exists( $product, 'get_duration_type' ) ? $product->get_duration_type() : '';
			$this->types[ (int) $id ] = ( 'customer' === $duration_type ) ? 'flex' : 'ceremony';
		}

		return $this->types;
	}

	/**
	 * First product ID of a given type, canonical PL.
	 *
	 * @param string $type
	 * @return int
	 */
	private function first_of_type( $type ) {
		$types = $this->classify_products();
		foreach ( $types as $id => $t ) {
			if ( $t === $type ) {
				return (int) $id;
			}
		}
		return 0;
	}
}
