<?php
/**
 * Grid data — builds the server payload consumed by grid.js via wp_localize_script.
 *
 * The WPML seam of the whole plasterek: availability axis on canonical PL IDs, user-facing
 * axis (label/description/url) from the translated product for the page language.
 * A booking is never a WPML-re-synced entity.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * One payload per render. No per-slot DB work — reads through the product API and resource meta.
 */
final class WBC_Grid_Data {

	const WINDOW_DAYS = 8; // today + 7.

	// Hard stop for the month-select loop: 12 horizon months + the current partial month.
	const HORIZON_MONTHS_HARD_CAP = 13;

	/** @var WBC_Config */
	private $config;

	/** @var WBC_WPML_Guard */
	private $wpml_guard;

	/** @var WBC_Hosts|null */
	private $hosts;

	/**
	 * @param WBC_Config     $config
	 * @param WBC_WPML_Guard $wpml_guard
	 * @param WBC_Hosts|null $hosts      Host registry; null means the payload carries no host field.
	 */
	public function __construct( WBC_Config $config, WBC_WPML_Guard $wpml_guard, ?WBC_Hosts $hosts = null ) {
		$this->config     = $config;
		$this->wpml_guard = $wpml_guard;
		$this->hosts      = $hosts;
	}

	/**
	 * Assemble the payload for a language.
	 *
	 * @param string|null $lang Language code (null = current).
	 * @return array<string,mixed>
	 */
	public function build_payload( $lang = null ) {
		if ( null === $lang ) {
			$lang = apply_filters( 'wpml_current_language', null );
		}

		$canonical_ids = $this->config->get_product_ids();
		$days          = $this->build_days();

		$products = array();
		foreach ( $canonical_ids as $canonical_id ) {
			$descriptor = $this->build_product_descriptor( (int) $canonical_id, $lang );
			if ( null !== $descriptor ) {
				$products[ (string) $canonical_id ] = $descriptor;
			}
		}

		return array(
			'restUrl'    => esc_url_raw( rest_url( 'wc-bookings/v1/products/slots' ) ),
			// WPML resolves the cart page per language, so the toast link lands on /koszyk/,
			// /en/cart/ or /de/warenkorb/ to match the page.
			'cartUrl'    => function_exists( 'wc_get_cart_url' ) ? esc_url_raw( wc_get_cart_url() ) : '',
			'productIds' => array_map( 'intval', $canonical_ids ),
			'lang'       => (string) $lang,
			'minDate'    => $days ? $days[0]['key'] : '',
			'maxDate'    => $this->fetch_max_date( $days ),
			'days'       => $days,
			/*
			 * Navigation lexicon. Day tiles for pages beyond the first are composed CLIENT-side
			 * (calendar arithmetic on Y-m-d strings is TZ-free in UTC), but every LABEL comes from
			 * wp_date on this side — the WPML seam stays server-side, Intl never renders text.
			 * `days` above stays as-is: it is the initial page AND the legacy contract an old
			 * cached bundle still reads.
			 */
			'todayKey'     => $days ? $days[0]['key'] : '',
			'horizonEndKey' => $this->horizon_end_key(),
			'weekdays'     => $this->weekday_lexicon( 'D' ),
			'weekdaysLong' => $this->weekday_lexicon( 'l' ),
			'months'       => $this->months_lexicon(),
			'monthsGen'    => $this->months_genitive(),
			'products'   => $products,
			'i18n'       => $this->build_i18n(),
		);
	}

	/**
	 * Midnight "today" in the site timezone — the shared base of every calendar computation here.
	 *
	 * @return DateTimeImmutable
	 */
	private function today_midnight() {
		$today = new DateTimeImmutable( 'now', wp_timezone() );
		return $today->setTime( 0, 0, 0 );
	}

	/**
	 * Pure month-forward arithmetic. PHP's native `+N month` overflows the day-of-month
	 * (Aug 31 + 3 months = Dec 1) — same boundary class as the v1.0.4 off-by-one — so an
	 * overflow clamps to the last day of the INTENDED target month. Static and WP-free on
	 * purpose: testable in a bare CLI harness with mocked dates (kryterium 1.1).
	 *
	 * @param DateTimeImmutable $today  Base day (midnight).
	 * @param int               $months Months forward.
	 * @return DateTimeImmutable
	 */
	public static function wbc_horizon_end( DateTimeImmutable $today, $months ) {
		$months   = (int) $months;
		$end      = $today->modify( '+' . $months . ' month' );
		$expected = ( ( (int) $today->format( 'n' ) - 1 + $months ) % 12 ) + 1;
		if ( (int) $end->format( 'n' ) !== $expected ) {
			$end = $end->modify( 'last day of last month' );
		}
		return $end;
	}

	/**
	 * Last navigable day (inclusive), Y-m-d.
	 *
	 * @return string
	 */
	private function horizon_end_key() {
		return self::wbc_horizon_end( $this->today_midnight(), $this->config->get_horizon_months() )->format( 'Y-m-d' );
	}

	/**
	 * Localized weekday names indexed 0..6 by JS getUTCDay() convention (0 = Sunday). Short names
	 * are uppercased exactly like build_days() tiles, long names keep the locale's natural case.
	 *
	 * @param string $format 'D' (short) or 'l' (long).
	 * @return string[]
	 */
	private function weekday_lexicon( $format ) {
		$tz    = wp_timezone();
		$today = $this->today_midnight();
		$out   = array_fill( 0, 7, '' );

		for ( $i = 0; $i < 7; $i++ ) {
			$day   = $today->modify( '+' . $i . ' day' );
			$index = (int) $day->format( 'w' );
			$label = (string) wp_date( $format, $day->getTimestamp(), $tz );
			if ( 'D' === $format ) {
				$label = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $label, 'UTF-8' ) : strtoupper( $label );
			}
			$out[ $index ] = trim( $label );
		}

		return $out;
	}

	/**
	 * Months intersecting [today, horizonEnd] for the month select. The year is appended
	 * PER OPTION, only for months that fall outside the current year, so a list never mixes
	 * bare month names with year-qualified ones inside the same year.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	private function months_lexicon() {
		$tz     = wp_timezone();
		$today  = $this->today_midnight();
		$end    = DateTimeImmutable::createFromFormat( 'Y-m-d', $this->horizon_end_key(), $tz );
		if ( ! $end ) {
			return array();
		}

		$current_year = $today->format( 'Y' );
		$cursor       = $today->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		$months       = array();

		while ( $cursor <= $end && count( $months ) <= self::HORIZON_MONTHS_HARD_CAP ) {
			$format   = ( $cursor->format( 'Y' ) === $current_year ) ? 'F' : 'F Y';
			$months[] = array(
				'key'   => $cursor->format( 'Y-m' ),
				'label' => trim( (string) wp_date( $format, $cursor->getTimestamp(), $tz ) ),
			);
			$cursor = $cursor->modify( 'first day of next month' );
		}

		return $months;
	}

	/**
	 * Genitive month names 1..12 (index 0 = January) for date-range phrases ("12–19 sierpnia").
	 * PL has a real genitive in WP_Locale; EN/DE fall back to the nominative naturally.
	 *
	 * @return string[]
	 */
	private function months_genitive() {
		global $wp_locale;

		$tz  = wp_timezone();
		$out = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$label = '';
			if ( isset( $wp_locale ) && is_object( $wp_locale ) && method_exists( $wp_locale, 'get_month_genitive' ) ) {
				$label = (string) $wp_locale->get_month_genitive( $m );
			}
			if ( '' === $label ) {
				$ts    = mktime( 12, 0, 0, $m, 1, 2026 );
				$label = (string) wp_date( 'F', $ts, $tz );
			}
			$out[] = trim( $label );
		}

		return $out;
	}

	/**
	 * Upper bound for the slots fetch. The endpoint treats max_date as EXCLUSIVE:
	 * min_date=max_date=D returns zero records, max_date=D+1 returns D's slots (measured on
	 * staging against 2.2.9 and 3.7.0). Passing the last pill's own date would leave the eighth
	 * day permanently empty, so the bound is the day AFTER the last pill.
	 *
	 * @param array<int,array{key:string,label:string}> $days
	 * @return string
	 */
	private function fetch_max_date( $days ) {
		if ( empty( $days ) ) {
			return '';
		}

		$last = $days[ count( $days ) - 1 ]['key'];
		$dt   = DateTimeImmutable::createFromFormat( 'Y-m-d', $last, wp_timezone() );

		return $dt ? $dt->modify( '+1 day' )->format( 'Y-m-d' ) : $last;
	}

	/**
	 * today..+7 in the site timezone (Europe/Warsaw). The key is the TZ-safe bucketing backbone:
	 * grid.js matches slot.date.slice(0,10) to this key, never new Date().
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	private function build_days() {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'now', $tz );
		$today = $today->setTime( 0, 0, 0 );

		$days = array();
		for ( $i = 0; $i < self::WINDOW_DAYS; $i++ ) {
			$day  = $today->modify( '+' . $i . ' day' );
			$ts   = $day->getTimestamp();
			$name = wp_date( 'D', $ts, $tz );
			$name = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( (string) $name, 'UTF-8' ) : strtoupper( (string) $name );

			$days[] = array(
				'key'   => $day->format( 'Y-m-d' ),
				'label' => trim( $name . ' ' . wp_date( 'j', $ts, $tz ) ),
			);
		}

		return $days;
	}

	/**
	 * Build one product descriptor. Availability keys on canonical PL; presentation comes from the
	 * translated product. Missing translation → graceful fallback to canonical (no fatal).
	 *
	 * @param int         $canonical_id
	 * @param string|null $lang
	 * @return array<string,mixed>|null
	 */
	private function build_product_descriptor( $canonical_id, $lang ) {
		$translated_id = $this->wpml_guard->wbc_translate_label( $canonical_id, $lang );
		$product       = wc_get_product( $translated_id );

		if ( ! $product || ! is_object( $product ) ) {
			// Fallback: presentation product missing in this language, use canonical.
			$translated_id = $canonical_id;
			$product       = wc_get_product( $canonical_id );
		}
		if ( ! $product || ! is_object( $product ) ) {
			return null;
		}

		/*
		 * Numbers are read off the canonical PL product; presentation (label,
		 * description, url, add-to-cart id) off the translation. The anchor
		 * resource is untranslatable (pule-jezykowe), so every language product
		 * shares one resource and a translation resolves the same shared resource
		 * as the canonical. Reading numerically on the canonical keeps the grid
		 * language-independent either way — defensive, not required by the data.
		 */
		$canonical_product = ( (int) $translated_id === (int) $canonical_id ) ? $product : wc_get_product( $canonical_id );
		if ( ! $canonical_product || ! is_object( $canonical_product ) ) {
			$canonical_product = $product;
		}

		$type        = $this->config->get_product_type( $canonical_id );
		$min_persons = $this->int_call( $canonical_product, 'get_min_persons', 1 );
		$max_persons = $this->int_call( $canonical_product, 'get_max_persons', $this->int_call( $canonical_product, 'get_qty', 18 ) );
		$capacity    = $this->resource_capacity( $canonical_product );

		$descriptor = array(
			'id'              => (int) $canonical_id,
			'addToCartId'    => (int) $translated_id,
			'type'            => $type,
			'label'           => $product->get_name(),
			'description'     => $this->clean_text( $product->get_description() ),
			'image'           => $this->product_image( $product, $canonical_product ),
			'durationText'    => $this->duration_text( $canonical_product, $type ),
			'minPersons'      => $min_persons,
			'maxPersons'      => $max_persons,
			'capacity'        => $capacity,
			'personsControl'  => $this->persons_control( $min_persons, $max_persons ),
			'durationControl' => $this->duration_control( $canonical_product, $type ),
			'productUrl'      => get_permalink( $translated_id ),
			'rate'            => $this->rate( $product ),
			'fields'          => $this->form_fields( $product ),
			'hostDefault'     => $this->host_default( $canonical_id ),
			'hosts'           => $this->host_map( $canonical_id ),
		);

		return $descriptor;
	}

	/**
	 * The product's default host, resolved to a name and a biography.
	 *
	 * Read from the CANONICAL product, exactly like event capacity. A value written on a
	 * translation is invisible to the runtime, and that is deliberate: a person's name is one
	 * string across every language.
	 *
	 * @param int $canonical_id ID produktu na kanonie PL.
	 * @return array{name:string,bio:string,image:array|null}|null
	 */
	private function host_default( $canonical_id ) {
		if ( ! $this->hosts ) {
			return null;
		}

		return $this->hosts->get_host( (int) get_post_meta( (int) $canonical_id, WBC_Config::META_HOST_DEFAULT, true ) );
	}

	/**
	 * Per-slot host overrides, keyed `ISOWeekday|HH:MM`.
	 *
	 * Entries pointing at a deleted or unpublished person are dropped here rather than on the
	 * front end: the client receives display-ready data only and never needs to know the registry.
	 *
	 * @param int $canonical_id ID produktu na kanonie PL.
	 * @return array<string,array{name:string,bio:string,image:array|null}>
	 */
	private function host_map( $canonical_id ) {
		if ( ! $this->hosts ) {
			return array();
		}

		$map = get_post_meta( (int) $canonical_id, WBC_Config::META_HOST_MAP, true );
		if ( ! is_array( $map ) ) {
			return array();
		}

		$out = array();
		foreach ( $map as $key => $host_id ) {
			if ( ! preg_match( '/^[1-7]\|\d{2}:\d{2}$/', (string) $key ) ) {
				continue;
			}
			$host = $this->hosts->get_host( (int) $host_id );
			if ( null !== $host ) {
				$out[ (string) $key ] = $host;
			}
		}

		return $out;
	}

	/**
	 * Persons control is data-driven (K1): the stepper exists exactly when the Bookings
	 * configuration leaves the customer a real choice (min < max). Equal bounds mean the
	 * count is fixed by configuration — no control, the hidden field carries the value.
	 * Product type deliberately plays no part here: wp-admin steers the behaviour
	 * (wymaganie klientki #4), so flipping min/max toggles the stepper without code.
	 *
	 * @param int $min
	 * @param int $max
	 * @return array<string,mixed>
	 */
	private function persons_control( $min, $max ) {
		$min = (int) $min;
		$max = (int) $max;

		if ( $min < $max ) {
			return array(
				'enabled' => true,
				'min'     => $min,
				'max'     => $max,
			);
		}
		return array(
			'enabled' => false,
			'fixed'   => max( $min, 1 ),
		);
	}

	/**
	 * @param object $product
	 * @param string $type
	 * @return array<string,mixed>
	 */
	private function duration_control( $product, $type ) {
		$unit = method_exists( $product, 'get_duration_unit' ) ? (string) $product->get_duration_unit() : 'hour';

		if ( 'flex' === $type ) {
			return array(
				'enabled' => true,
				'min'     => $this->int_call( $product, 'get_min_duration', 1 ),
				'max'     => $this->int_call( $product, 'get_max_duration', 6 ),
				'step'    => 1,
				// Real block size in duration units. The select always steps blocks by 1, but the
				// grid's duration clamp is only valid when one block == one hour == slot spacing;
				// a 2-hour-block config must disable the clamp, and a hardcoded step can't say so
				// (impl-review Fazy 2, MEDIUM).
				'block'   => $this->int_call( $product, 'get_duration', 1 ),
				'unit'    => $unit,
			);
		}

		return array(
			'enabled' => false,
			'fixed'   => $this->int_call( $product, 'get_duration', 2 ),
			'unit'    => $unit,
		);
	}

	/**
	 * Native add-to-cart field names. Persons field depends on whether custom person types exist.
	 *
	 * @param object $product
	 * @return array<string,string>
	 */
	private function form_fields( $product ) {
		$person_field = 'wc_bookings_field_persons';

		if ( method_exists( $product, 'has_person_types' ) && $product->has_person_types() && method_exists( $product, 'get_person_types' ) ) {
			$types = $product->get_person_types();
			if ( is_array( $types ) && ! empty( $types ) ) {
				$first = reset( $types );
				if ( is_object( $first ) && method_exists( $first, 'get_id' ) ) {
					$person_field = 'wc_bookings_field_persons_' . (int) $first->get_id();
				}
			}
		}

		return array(
			'start'    => 'wc_bookings_field_start_date_time',
			'persons'  => $person_field,
			'duration' => 'wc_bookings_field_duration',
		);
	}

	/**
	 * Resource capacity (informational; grid computes X/Y per slot from available+booked).
	 *
	 * @param object $product
	 * @return int
	 */
	private function resource_capacity( $product ) {
		$resource_id = $this->config->get_resource_id();
		if ( $resource_id && method_exists( $product, 'get_resource' ) ) {
			$resource = $product->get_resource( $resource_id );
			if ( is_object( $resource ) && method_exists( $resource, 'get_qty' ) ) {
				$qty = (int) $resource->get_qty();
				if ( $qty > 0 ) {
					return $qty;
				}
			}
		}
		return $this->int_call( $product, 'get_qty', 18 );
	}

	/**
	 * @param object $product
	 * @param string $type
	 * @return string
	 */
	private function duration_text( $product, $type ) {
		$unit_label = __( 'godz.', 'woobookings-custom' );

		if ( 'flex' === $type ) {
			$min = $this->int_call( $product, 'get_min_duration', 1 );
			$max = $this->int_call( $product, 'get_max_duration', 6 );
			if ( $min === $max ) {
				return $min . ' ' . $unit_label;
			}
			return $min . '–' . $max . ' ' . $unit_label;
		}

		$fixed = $this->int_call( $product, 'get_duration', 2 );
		return $fixed . ' ' . $unit_label;
	}

	/**
	 * Optional per-person rate for future card pricing (not rendered in slice 1).
	 *
	 * @param object $product
	 * @return float|null
	 */
	private function rate( $product ) {
		if ( method_exists( $product, 'get_cost' ) ) {
			return (float) $product->get_cost();
		}
		return null;
	}

	/**
	 * Call an int-returning method with a guard/default.
	 *
	 * @param object $product
	 * @param string $method
	 * @param int    $default
	 * @return int
	 */
	private function int_call( $product, $method, $default ) {
		if ( method_exists( $product, $method ) ) {
			$value = $product->$method();
			if ( is_numeric( $value ) ) {
				return (int) $value;
			}
		}
		return (int) $default;
	}

	/**
	 * Product image for the details dialog: from the translation, or from the canonical product
	 * when the translation has none.
	 *
	 * WCML copies the image onto a translation only when the source is saved with sync enabled.
	 * The editor sets the image on the source product and does not need to know whether the copy
	 * arrived: translations get the same image through this fallback. This is the IMAGE channel;
	 * the description stays plain text (clean_text).
	 *
	 * @param WC_Product $product           Product in the page language.
	 * @param WC_Product $canonical_product Canonical (source-language) product.
	 * @return array|null
	 */
	private function product_image( $product, $canonical_product ) {
		$image = WBC_Image::payload( $this->int_call( $product, 'get_image_id', 0 ) );
		if ( null === $image && $canonical_product !== $product ) {
			$image = WBC_Image::payload( $this->int_call( $canonical_product, 'get_image_id', 0 ) );
		}
		return $image;
	}

	/**
	 * Strip shortcodes/tags from meta text; JS inserts via textContent, this keeps it clean.
	 *
	 * @param string $text
	 * @return string
	 */
	private function clean_text( $text ) {
		$text = (string) $text;
		$text = wp_strip_all_tags( strip_shortcodes( $text ) );
		return trim( $text );
	}

	/**
	 * UI strings (CTA/states/controls). Localized to the page language.
	 *
	 * @return array<string,string>
	 */
	private function build_i18n() {
		return array(
			'book'           => __( 'Book', 'woobookings-custom' ),
			'full'           => __( 'Brak miejsc', 'woobookings-custom' ),
			'past'           => __( 'Slot has passed', 'woobookings-custom' ),
			'persons'        => __( 'People', 'woobookings-custom' ),
			'duration'       => __( 'Duration', 'woobookings-custom' ),
			'hourShort'      => __( 'godz.', 'woobookings-custom' ),
			'seats'          => __( 'miejsc', 'woobookings-custom' ),
			'noSlots'        => __( 'No slots on this day', 'woobookings-custom' ),
			'loading'        => __( 'Loading slots…', 'woobookings-custom' ),
			'error'          => __( 'Could not load slots. Please refresh the page.', 'woobookings-custom' ),
			'close'          => __( 'Zamknij', 'woobookings-custom' ),
			'hostLabel'      => __( 'Host:', 'woobookings-custom' ),
			'daypickerLabel' => __( 'Day selection', 'woobookings-custom' ),
			'decrease'       => __( 'Fewer people', 'woobookings-custom' ),
			'increase'       => __( 'More people', 'woobookings-custom' ),
			'details'        => __( 'Session details', 'woobookings-custom' ),
			// The number of minutes is NOT typed here. It comes from WBC_Hold, the same constant
			// that drives the real expiry, so the copy cannot promise one thing while the system
			// does another after the first change to the window.
			'addedToCart'    => WBC_Hold::notice_text(),
			'goToCart'       => __( 'Go to cart', 'woobookings-custom' ),
			'addError'       => __( 'Could not add to cart.', 'woobookings-custom' ),
			// Nawigacja stronami dni (kroki to 8-dniowe strony, nie kalendarzowe tygodnie — copy
			// deliberately talks about slots, not weeks).
			'prevDays'       => __( 'Earlier slots', 'woobookings-custom' ),
			'nextDays'       => __( 'Later slots', 'woobookings-custom' ),
			'monthLabel'     => __( 'Month', 'woobookings-custom' ),
			// Status region announcements (SC 4.1.3). Three plural forms are supplied because the
			// client picks by locale rule; languages with two forms simply use one and many.
			'statusNone'     => __( 'no slots', 'woobookings-custom' ),
			'statusEnd'      => __( 'End of available slots.', 'woobookings-custom' ),
			'statusStart'    => __( 'Earliest range.', 'woobookings-custom' ),
			'slotOne'        => __( '%d termin', 'woobookings-custom' ),
			'slotFew'        => __( '%d terminy', 'woobookings-custom' ),
			'slotMany'       => __( '%d slots', 'woobookings-custom' ),
		);
	}
}
