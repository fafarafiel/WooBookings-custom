<?php
/**
 * Settings — codeless configuration of the resource + product IDs for production cutover.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce sub-page ("Rezerwacja WooBookings Custom") exposing the resource anchor and optional product
 * overrides. Empty overrides fall back to discovery. Gated on manage_woocommerce; saves through
 * the Settings API (core nonce), each field sanitized to an int.
 */
final class WBC_Settings {

	const OPTION_GROUP = 'woobookings_custom_settings';
	const PAGE_SLUG    = 'woobookings-custom';
	const CAPABILITY   = 'manage_woocommerce';

	/** @var WBC_Config */
	private $config;

	/**
	 * @param WBC_Config $config
	 */
	public function __construct( WBC_Config $config ) {
		$this->config = $config;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'unconfigured_notice' ) );
		add_action( 'admin_notices', array( $this, 'event_misconfig_notice' ) );
		add_action( 'admin_notices', array( $this, 'exclusivity_notice' ) );
	}

	/**
	 * Cap required by options.php for this option group — aligned with the menu's own cap.
	 *
	 * @return string
	 */
	public function settings_capability() {
		return self::CAPABILITY;
	}

	/**
	 * Warn when no resource anchor resolves to any product. Without this the failure is invisible:
	 * the grid renders an empty day and the cache flush loops over nothing, both without an error,
	 * so a mis-deploy reads as "no slots scheduled" rather than "the companion is unconfigured".
	 *
	 * @return void
	 */
	public function unconfigured_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || $this->config->is_configured() ) {
			return;
		}

		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
			esc_html__( 'Rezerwacja WooBookings Custom:', 'woobookings-custom' ),
			esc_html__( 'the plugin has no shared resource configured, or finds no product bound to it. The booking grid is empty and cache flushing does nothing.', 'woobookings-custom' ),
			esc_url( $url ),
			esc_html__( 'Configure the resource', 'woobookings-custom' )
		);
	}

	/**
	 * Warn about mis-configured events. Iterates
	 * products carrying the event-capacity meta DIRECTLY (meta_query), NOT get_product_ids(): that
	 * list is the discovery output and by construction only holds products already pinned to the
	 * anchor resource, so an "event without a resource" — the exact failure this must catch — would
	 * never appear in it and the notice would stay silent. A ghost event (cap > resource qty, or
	 * priced at zero) is the same class of quiet failure that bit this project five times; it must
	 * shout in wp-admin instead of shipping an empty or nonsensical grid.
	 *
	 * @return void
	 */
	public function event_misconfig_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$anchor = $this->config->get_resource_id();
		if ( $anchor <= 0 ) {
			// No anchor at all → unconfigured_notice already covers it; validating events against a
			// missing anchor would flag every event as "no resource" and drown the real message.
			return;
		}

		// Language-agnostic: an event can be authored in any language; suppress_filters avoids
		// WPML scoping the set to the current admin language.
		$event_ids = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => WBC_Config::META_EVENT_CAPACITY,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		if ( empty( $event_ids ) ) {
			return;
		}

		$problems = array();
		foreach ( $event_ids as $pid ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product_Booking' ) ) {
				continue;
			}

			$cap  = (int) get_post_meta( $pid, WBC_Config::META_EVENT_CAPACITY, true );
			$name = $product->get_name();

			// The cap meta was found on the RAW product by the meta_query above, but every runtime
			// reader (is_event / get_event_capacity and the three Faza-2 filters) resolves it on the
			// CANONICAL PL product, and WCML does not copy this meta onto translations. A cap set on an
			// EN/DE translation instead of the PL original therefore passes the meta_query yet reads
			// back as zero at runtime: the event silently degrades to a plain 18-seat ceremony with no
			// cap and no whole-evening blackout. is_event() canonicalizes, so a raw-meta product it
			// reports as NOT an event is exactly this misplacement, the quiet failure this notice exists
			// to kill. Shout instead of shipping a silently broken event.
			if ( ! $this->config->is_event( $pid ) ) {
				/* translators: %s: product name */
				$problems[] = sprintf( __( '"%s": event capacity is set on a translation instead of the source product. The plugin reads capacity from the source, so this event applies neither its seat limit nor its exclusivity. Set the capacity on the source-language product.', 'woobookings-custom' ), $name );
				continue;
			}

			$resource_ids = method_exists( $product, 'get_resource_ids' )
				? array_map( 'intval', (array) $product->get_resource_ids() )
				: array();

			if ( ! in_array( $anchor, $resource_ids, true ) ) {
				/* translators: %s: product name */
				$problems[] = sprintf( __( '"%s": the event is not bound to the shared resource, so it will not appear in the grid.', 'woobookings-custom' ), $name );
				// Without the anchor on the product the seat cap cannot be validated against it.
				continue;
			}

			if ( ! $this->event_has_price( $product, $anchor ) ) {
				/* translators: %s: product name */
				$problems[] = sprintf( __( '"%s": the event has no price set (base, block, resource and person-type costs are all 0).', 'woobookings-custom' ), $name );
			}

			$qty = 0;
			if ( method_exists( $product, 'get_resource' ) ) {
				$resource = $product->get_resource( $anchor );
				if ( is_object( $resource ) && method_exists( $resource, 'get_qty' ) ) {
					$qty = (int) $resource->get_qty();
				}
			}
			if ( $qty > 0 && $cap > $qty ) {
				/* translators: 1: product name, 2: event capacity, 3: resource capacity */
				$problems[] = sprintf( __( '"%1$s": event capacity (%2$d) exceeds resource capacity (%3$d).', 'woobookings-custom' ), $name, $cap, $qty );
			}

			// An event with a cap but no product-level "bookable=yes" availability contributes zero
			// blackout ranges, so whole-evening exclusivity silently vanishes while the cap still
			// enforces (impl-review Faza 2, MEDIUM). "Unconfigured must be loud" — flag it.
			$has_bookable_window = false;
			if ( method_exists( $product, 'get_availability' ) ) {
				foreach ( (array) $product->get_availability() as $entry ) {
					if ( is_array( $entry ) && isset( $entry['bookable'] ) && 'yes' === $entry['bookable'] ) {
						$has_bookable_window = true;
						break;
					}
				}
			}
			if ( ! $has_bookable_window ) {
				/* translators: %s: product name */
				$problems[] = sprintf( __( '"%s": the event has no bookable availability rule, so it will not block regular sessions or open entry in its slot.', 'woobookings-custom' ), $name );
			}
		}

		if ( empty( $problems ) ) {
			return;
		}

		$items = '';
		foreach ( $problems as $problem ) {
			$items .= '<li>' . esc_html( $problem ) . '</li>';
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><ul style="list-style:disc;margin-left:2em;">%s</ul></div>',
			esc_html__( 'WooBookings Custom, misconfigured event:', 'woobookings-custom' ),
			esc_html__( 'fix the products below, otherwise they will not behave correctly in the grid:', 'woobookings-custom' ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item escaped above.
		);
	}

	/**
	 * Whether an event product carries a real price anywhere WooCommerce Bookings reads cost from.
	 * The per-person session model stores its price in the BLOCK cost, not the base
	 * cost, so get_cost() alone is 0 for a correctly-priced product — checking only that field
	 * false-flags every properly configured event as "no price". Mirrors the cost inputs summed in
	 * WC_Bookings_Cost_Calculation (class-wc-bookings-cost-calculation.php:387,395,418): product
	 * base + block, anchor resource base + block, and per-person-type base + block. A genuinely
	 * unpriced event (all zero) still flags.
	 *
	 * @param object $product WC_Product_Booking.
	 * @param int    $anchor  Shared resource ID.
	 * @return bool
	 */
	private function event_has_price( $product, $anchor ) {
		if ( method_exists( $product, 'get_cost' ) && (float) $product->get_cost() > 0 ) {
			return true;
		}
		if ( method_exists( $product, 'get_block_cost' ) && (float) $product->get_block_cost() > 0 ) {
			return true;
		}

		if ( $anchor > 0 && method_exists( $product, 'get_resource' ) ) {
			$resource = $product->get_resource( $anchor );
			if ( is_object( $resource ) ) {
				if ( method_exists( $resource, 'get_base_cost' ) && (float) $resource->get_base_cost() > 0 ) {
					return true;
				}
				if ( method_exists( $resource, 'get_block_cost' ) && (float) $resource->get_block_cost() > 0 ) {
					return true;
				}
			}
		}

		if ( method_exists( $product, 'has_person_types' ) && $product->has_person_types() && method_exists( $product, 'get_person_types' ) ) {
			foreach ( (array) $product->get_person_types() as $person_type ) {
				if ( ! is_object( $person_type ) ) {
					continue;
				}
				if ( method_exists( $person_type, 'get_cost' ) && (float) $person_type->get_cost() > 0 ) {
					return true;
				}
				if ( method_exists( $person_type, 'get_block_cost' ) && (float) $person_type->get_block_cost() > 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Warn when the ceremony→open exclusivity cannot work or silently extinguishes the open
	 * product. Conservative heuristic, warning level only:
	 *   (a) a ceremony on the anchor has NO bookable=yes availability rule at all — the tier
	 *       blackout has no material, so the open entry stays bookable during the ceremony;
	 *   (b) the ceremony's single simple time rule covers the open product's single simple time
	 *       rule entirely — the blackout wipes the open product for the whole day, silently.
	 * Coverage is judged ONLY between rules of the same day scope (plain time↔plain time, or
	 * time:N↔time:N with the same N). Multiple rules, rrule/custom types or mismatched day
	 * scopes get no verdict — a false alarm would train Anna to ignore the notice.
	 *
	 * Screen-gated via get_current_screen (unlike the two legacy notices above — deliberate):
	 * this validation loads products and parses rules, so it runs only where the configuration
	 * it validates is edited, not on every wp-admin load.
	 *
	 * @return void
	 */
	public function exclusivity_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen  = get_current_screen();
		$allowed = array( 'product', 'edit-product', 'woocommerce_page_' . self::PAGE_SLUG );
		if ( ! is_object( $screen ) || ! in_array( $screen->id, $allowed, true ) ) {
			return;
		}

		$flex_id = $this->config->get_flex_product_id();
		if ( $flex_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			// Without an open/flex product there is nothing exclusivity could extinguish.
			return;
		}

		$flex = wc_get_product( $flex_id );
		$flex_entries = ( is_object( $flex ) && method_exists( $flex, 'get_availability' ) )
			? $this->bookable_yes_entries( $flex )
			: array();

		$problems = array();
		foreach ( $this->config->get_product_ids() as $pid ) {
			if ( $this->config->is_event( $pid ) || 'ceremony' !== $this->config->get_product_type( $pid ) ) {
				continue;
			}
			$ceremony = wc_get_product( $pid );
			if ( ! is_object( $ceremony ) || ! method_exists( $ceremony, 'get_availability' ) ) {
				continue;
			}

			$cer_entries = $this->bookable_yes_entries( $ceremony );

			if ( empty( $cer_entries ) ) {
				/* translators: %s: ceremony product name */
				$problems[] = sprintf( __( '"%s": the session has no bookable availability rule, so the exclusivity that replaces open entry has nothing to derive from. Open entry stays bookable during the session.', 'woobookings-custom' ), $ceremony->get_name() );
				continue;
			}

			// Coverage verdict only for the simple single-rule against single-rule case.
			if ( 1 !== count( $cer_entries ) || 1 !== count( $flex_entries ) ) {
				continue;
			}
			if ( $this->covers_entire_window( $cer_entries[0], $flex_entries[0] ) ) {
				$problems[] = sprintf(
					/* translators: 1: ceremony product name, 2: ceremony rule from, 3: ceremony rule to, 4: open product name, 5: open rule from, 6: open rule to */
					__( '"%1$s": the session rule (%2$s to %3$s) covers the entire open-entry window of "%4$s" (%5$s to %6$s). Open entry is suppressed completely and nobody can book it.', 'woobookings-custom' ),
					$ceremony->get_name(),
					(string) $cer_entries[0]['from'],
					(string) $cer_entries[0]['to'],
					is_object( $flex ) ? $flex->get_name() : (string) $flex_id,
					(string) $flex_entries[0]['from'],
					(string) $flex_entries[0]['to']
				);
			}
		}

		if ( empty( $problems ) ) {
			return;
		}

		$items = '';
		foreach ( $problems as $problem ) {
			$items .= '<li>' . esc_html( $problem ) . '</li>';
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><ul style="list-style:disc;margin-left:2em;">%s</ul></div>',
			esc_html__( 'WooBookings Custom, session exclusivity:', 'woobookings-custom' ),
			esc_html__( 'check the availability rules of the products below:', 'woobookings-custom' ),
			$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item escaped above.
		);
	}

	/**
	 * The bookable=yes entries of a product's RAW availability (get_availability, never
	 * get_availability_rules — the latter fires the tier-blackout filter).
	 *
	 * @param object $product WC_Product_Booking.
	 * @return array<int,array>
	 */
	private function bookable_yes_entries( $product ) {
		$out = array();
		foreach ( (array) $product->get_availability() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['bookable'] ) && 'yes' === $entry['bookable'] ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Whether a simple time rule covers another one entirely. True ONLY when both rules share
	 * the same day scope — plain 'time' vs plain 'time', or 'time:N' vs 'time:N' with the same
	 * N — and the first window contains the second. A day-scoped ceremony rule (one day of
	 * seven) must never be judged as covering a plain daily open rule: that is a false alarm.
	 *
	 * @param array $covering Candidate covering rule (ceremony).
	 * @param array $covered  Candidate covered rule (open).
	 * @return bool
	 */
	private function covers_entire_window( $covering, $covered ) {
		$covering_type = isset( $covering['type'] ) ? (string) $covering['type'] : '';
		$covered_type  = isset( $covered['type'] ) ? (string) $covered['type'] : '';

		if ( ! preg_match( '/^time(:[1-7])?$/', $covering_type ) || $covering_type !== $covered_type ) {
			return false;
		}

		$covering_from = $this->time_to_minutes( isset( $covering['from'] ) ? $covering['from'] : '' );
		$covering_to   = $this->time_to_minutes( isset( $covering['to'] ) ? $covering['to'] : '' );
		$covered_from  = $this->time_to_minutes( isset( $covered['from'] ) ? $covered['from'] : '' );
		$covered_to    = $this->time_to_minutes( isset( $covered['to'] ) ? $covered['to'] : '' );

		if ( null === $covering_from || null === $covering_to || null === $covered_from || null === $covered_to ) {
			return false;
		}

		// The vendor reads an end of "00:00" as 24:00 (process_availability_rules) — mirror it.
		$covering_to = 0 === $covering_to ? 1440 : $covering_to;
		$covered_to  = 0 === $covered_to ? 1440 : $covered_to;

		// Judge only plain forward windows. A reverse rule (to < from = overnight, vendor-legal:
		// "Reverse time rule", rule-manager :1170) leaves real bookable hours after midnight that
		// this containment test cannot see — no verdict, never a false alarm (impl-review Fazy 2).
		if ( $covering_from >= $covering_to || $covered_from >= $covered_to ) {
			return false;
		}

		return $covering_from <= $covered_from && $covering_to >= $covered_to;
	}

	/**
	 * Parse "HH:MM" into minutes since midnight; null when the value is not a plain time
	 * (an unparseable rule gets no verdict rather than a guessed one).
	 *
	 * @param mixed $value Raw rule from/to value.
	 * @return int|null
	 */
	private function time_to_minutes( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) ) {
			return null;
		}
		return ( (int) $m[1] * 60 ) + (int) $m[2];
	}

	/**
	 * Add the sub-page under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Rezerwacja WooBookings Custom', 'woobookings-custom' ),
			__( 'Rezerwacja WooBookings Custom', 'woobookings-custom' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option fields with absint sanitization.
	 *
	 * @return void
	 */
	public function register_settings() {
		/*
		 * options.php gates the save on 'manage_options' unless this filter says otherwise, while
		 * the menu below is gated on 'manage_woocommerce'. Without this, a shop manager sees the
		 * screen, fills it in, hits Save and gets wp_die() — the cap for reading and the cap for
		 * writing must be the same one.
		 */
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'settings_capability' ) );

		$fields = array(
			WBC_Config::OPTION_RESOURCE,
			WBC_Config::OPTION_FLEX,
			WBC_Config::OPTION_CEREMONY,
		);

		foreach ( $fields as $option ) {
			register_setting(
				self::OPTION_GROUP,
				$option,
				array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'default'           => 0,
				)
			);
		}

		register_setting(
			self::OPTION_GROUP,
			WBC_Config::OPTION_HORIZON,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_horizon' ),
				'default'           => WBC_Config::HORIZON_DEFAULT,
			)
		);

		add_settings_section(
			'woobookings_custom_main',
			__( 'Resource and product binding', 'woobookings-custom' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		$this->add_field( WBC_Config::OPTION_RESOURCE, __( 'Shared resource ID', 'woobookings-custom' ) );
		$this->add_field( WBC_Config::OPTION_FLEX, __( 'ID produktu „wynajem/flex" (puste = discovery)', 'woobookings-custom' ) );
		$this->add_field( WBC_Config::OPTION_CEREMONY, __( 'ID produktu „ceremonia" (puste = discovery)', 'woobookings-custom' ) );

		add_settings_field(
			WBC_Config::OPTION_HORIZON,
			esc_html__( 'Booking horizon (months)', 'woobookings-custom' ),
			array( $this, 'render_horizon_field' ),
			self::PAGE_SLUG,
			'woobookings_custom_main'
		);
	}

	/**
	 * Clamp the horizon to the supported window. absint alone would let 99 through to the DB and
	 * the getter would clamp it invisibly — the stored value should already be the effective one.
	 *
	 * @param mixed $value
	 * @return int
	 */
	public function sanitize_horizon( $value ) {
		return min( WBC_Config::HORIZON_MAX, max( WBC_Config::HORIZON_MIN, absint( $value ) ) );
	}

	/**
	 * @return void
	 */
	public function render_horizon_field() {
		$value = $this->config->get_horizon_months();
		printf(
			'<input type="number" min="%1$s" max="%2$s" step="1" name="%3$s" id="%3$s" value="%4$s" class="small-text" />',
			esc_attr( (string) WBC_Config::HORIZON_MIN ),
			esc_attr( (string) WBC_Config::HORIZON_MAX ),
			esc_attr( WBC_Config::OPTION_HORIZON ),
			esc_attr( (string) $value )
		);
		echo '<p class="description">' . esc_html__( 'How far ahead a visitor can page through the grid. The real ceiling is also set by each product\'s own maximum bookable window (12 months by default); beyond it the grid shows no slots.', 'woobookings-custom' ) . '</p>';
	}

	/**
	 * @param string $option
	 * @param string $label
	 * @return void
	 */
	private function add_field( $option, $label ) {
		add_settings_field(
			$option,
			esc_html( $label ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'woobookings_custom_main',
			array( 'option' => $option )
		);
	}

	/**
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'The shared resource is the anchor: products bound to it are discovered automatically. Use the ID override only during a production cutover.', 'woobookings-custom' ) . '</p>';
	}

	/**
	 * @param array<string,mixed> $args
	 * @return void
	 */
	public function render_number_field( $args ) {
		$option = isset( $args['option'] ) ? (string) $args['option'] : '';
		if ( '' === $option ) {
			return;
		}
		$value = (int) get_option( $option, 0 );
		printf(
			'<input type="number" min="0" step="1" name="%1$s" id="%1$s" value="%2$s" class="regular-text" />',
			esc_attr( $option ),
			esc_attr( (string) $value )
		);
	}

	/**
	 * Render the settings page + a live preview of what the grid resolves.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'woobookings-custom' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Rezerwacja WooBookings Custom — konfiguracja', 'woobookings-custom' ) . '</h1>';

		echo '<form action="options.php" method="post">';
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();
		echo '</form>';

		$product_ids = $this->config->get_product_ids();
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Co widzi grid', 'woobookings-custom' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Resource:', 'woobookings-custom' ) . '</strong> ' . esc_html( (string) $this->config->get_resource_id() ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Produkty (kanon PL):', 'woobookings-custom' ) . '</strong> ' . esc_html( implode( ', ', array_map( 'strval', $product_ids ) ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Flex:', 'woobookings-custom' ) . '</strong> ' . esc_html( (string) $this->config->get_flex_product_id() );
		echo ' &nbsp; <strong>' . esc_html__( 'Ceremonia:', 'woobookings-custom' ) . '</strong> ' . esc_html( (string) $this->config->get_ceremony_product_id() ) . '</p>';

		echo '</div>';
	}
}
