<?php
/**
 * WPML guard. Detaches booking duplication and maps product IDs across languages.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * WCML unconditionally hooks duplicate_booking_for_translations onto woocommerce_new_booking
 * (class-wcml-bookings.php:113), which spawns EN+DE copies of every booking. There is no
 * settings toggle for it — the only kill switch is remove_action from the companion. Bookings
 * must stay a single-language entity, never re-synced by WPML.
 */
final class WBC_WPML_Guard {

	/** @var int|null Memoized default-language object cache is per-request; keep it simple. */
	private $default_lang = null;

	/** @var array<int,int> Per-request memo: input id => canonical id. Hot path — the availability
	 * filters (WBC_Events) resolve the canonical id on every get_availability_rules call, and each then
	 * canonicalizes again inside get_event_capacity, so this caps wpml_object_id dispatch churn. */
	private $canonical_cache = array();

	/** Callback method WCML hooks onto woocommerce_new_booking to spawn copies in other languages. */
	const WCML_DUP_METHOD = 'duplicate_booking_for_translations';

	public function __construct() {
		/*
		 * WCML instantiates its bookings compatibility class (which adds the duplication hook)
		 * during its own init, AFTER plugins_loaded. Removing at construction (plugins_loaded:20)
		 * runs before that add_action and is a silent no-op. Defer to wp_loaded, by which point
		 * every plugin — WCML included — has finished wiring its hooks, and which still fires
		 * before any front-end add-to-cart can create a booking.
		 */
		add_action( 'wp_loaded', array( $this, 'detach_duplication' ), 1 );
	}

	/**
	 * Remove the WooCommerce Multilingual duplication callback so a booking stays one entity.
	 *
	 * WCML mirrors every new booking onto each translated product. A booking is a transaction,
	 * not translatable content, and the copies corrupt availability accounting on the shared
	 * resource. Marking the product non-translatable does not stop it; removing the callback does.
	 * Two strategies, belt-and-suspenders: (1) targeted remove_action against the resolved WCML
	 * instance; (2) a by-method-name sweep of the registered hook, which does not depend on
	 * getting the exact object identity WCML hooked with. Public so it can attach to wp_loaded.
	 */
	public function detach_duplication() {
		// Strategy 1 — targeted removal against the resolved instance (default priority 10).
		$wcml_bookings = $this->resolve_wcml_bookings();
		if ( $wcml_bookings && is_object( $wcml_bookings ) && method_exists( $wcml_bookings, self::WCML_DUP_METHOD ) ) {
			remove_action( 'woocommerce_new_booking', array( $wcml_bookings, self::WCML_DUP_METHOD ), 10 );
		}

		// Strategy 2 — sweep the hook and drop any callback whose method is the duplicator,
		// regardless of instance identity or registered priority.
		$this->sweep_duplication_callbacks();
	}

	/**
	 * Scan the woocommerce_new_booking hook and remove every callback that is an object method
	 * named duplicate_booking_for_translations, at whatever priority it was registered.
	 */
	private function sweep_duplication_callbacks() {
		global $wp_filter;

		if ( empty( $wp_filter['woocommerce_new_booking'] ) ) {
			return;
		}

		$hook = $wp_filter['woocommerce_new_booking'];
		if ( ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
			return;
		}

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$cb = isset( $registered['function'] ) ? $registered['function'] : null;
				if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) && is_object( $cb[0] ) && self::WCML_DUP_METHOD === $cb[1] ) {
					remove_action( 'woocommerce_new_booking', $cb, $priority );
				}
			}
		}
	}

	/**
	 * Resolve the WCML bookings compatibility instance. Prefer the global; fall back to the
	 * WCML DI container. No-op (returns null) when WPML/WCML is inactive.
	 *
	 * @return object|null
	 */
	private function resolve_wcml_bookings() {
		if ( isset( $GLOBALS['woocommerce_wpml'] ) && is_object( $GLOBALS['woocommerce_wpml'] ) ) {
			$wpml = $GLOBALS['woocommerce_wpml'];
			if ( isset( $wpml->bookings ) && is_object( $wpml->bookings ) ) {
				return $wpml->bookings;
			}
		}

		if ( class_exists( '\WCML\Container\Container' ) ) {
			try {
				$container = \WCML\Container\Container::instance();
				if ( is_object( $container ) && method_exists( $container, 'get' ) && class_exists( '\WCML_Bookings' ) ) {
					$maybe = $container->get( '\WCML_Bookings' );
					if ( is_object( $maybe ) ) {
						return $maybe;
					}
				}
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Whether WPML is active in this request.
	 *
	 * @return bool
	 */
	public function is_wpml_active() {
		return function_exists( 'icl_object_id' ) || has_filter( 'wpml_object_id' );
	}

	/**
	 * The site's default (canonical) language code.
	 *
	 * @return string|null
	 */
	private function default_language() {
		if ( null === $this->default_lang ) {
			$this->default_lang = apply_filters( 'wpml_default_language', null );
		}
		return $this->default_lang;
	}

	/**
	 * Map any product ID to its canonical (default-language, i.e. PL) counterpart. Availability
	 * is always queried on canonical IDs so slots stay consistent across pl/en/de. Falls back to
	 * the input when WPML is inactive or no mapping exists.
	 *
	 * @param int $id Product ID.
	 * @return int
	 */
	public function wbc_canonical_id( $id ) {
		$id = (int) $id;
		if ( ! $id || ! $this->is_wpml_active() ) {
			return $id;
		}
		if ( isset( $this->canonical_cache[ $id ] ) ) {
			return $this->canonical_cache[ $id ];
		}
		$default = $this->default_language();
		if ( ! $default ) {
			return $id;
		}
		$mapped                        = apply_filters( 'wpml_object_id', $id, 'product', true, $default );
		$canonical                     = $mapped ? (int) $mapped : $id;
		$this->canonical_cache[ $id ]  = $canonical;
		return $canonical;
	}

	/**
	 * Map a product ID to its translation for a given language. Used for user-facing labels,
	 * URLs and the add-to-cart target (order must carry the page language). Falls back to the
	 * input when the translation is missing.
	 *
	 * @param int         $id   Product ID.
	 * @param string|null $lang Target language code (null = current).
	 * @return int
	 */
	public function wbc_translate_label( $id, $lang = null ) {
		$id = (int) $id;
		if ( ! $id || ! $this->is_wpml_active() ) {
			return $id;
		}
		if ( null === $lang ) {
			$lang = apply_filters( 'wpml_current_language', null );
		}
		if ( ! $lang ) {
			return $id;
		}
		$mapped = apply_filters( 'wpml_object_id', $id, 'product', true, $lang );
		return $mapped ? (int) $mapped : $id;
	}
}
