<?php
/**
 * Polish wording for the WooCommerce Bookings messages a grid visitor can actually see.
 *
 * Bookings 3.7.0 ships no pl_PL translation, so the cart refusals arrive in English inside a
 * Polish page ("The minimum Uczestnicy per group is 1"). A translation plugin would fix that too, but
 * as data outside this plugin — rolling the plugin back would leave it behind, and a vendor
 * update would need a manual re-sync. Here the wording is versioned with the companion.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Closed list of msgids from Bookings 3.7.0 (class-wc-product-booking.php:1772-1791, 2780-2822,
 * class-wc-booking-cart-manager.php:221).
 * Only the `woocommerce-bookings` domain and only Polish locales are touched; every other string
 * passes through untouched.
 */
final class WBC_Vendor_Strings {

	const DOMAIN = 'woocommerce-bookings';

	/**
	 * @return void
	 */
	public function register() {
		add_filter( 'gettext_' . self::DOMAIN, array( $this, 'translate' ), 10, 2 );
		add_filter( 'ngettext_' . self::DOMAIN, array( $this, 'translate_plural' ), 10, 4 );
	}

	/**
	 * @param string $translation Translated text.
	 * @param string $text        Original msgid.
	 * @return string
	 */
	public function translate( $translation, $text ) {
		// The msgid match runs first: this filter sees every Bookings string on every screen,
		// and determine_locale() is only worth calling for the handful we own.
		switch ( $text ) {
			case 'The maximum persons per group is %d':
				$polish = 'Maksymalna liczba osób w rezerwacji to %d.';
				break;
			case 'The minimum persons per group is %d':
				$polish = 'Minimalna liczba osób w rezerwacji to %d.';
				break;
			case 'The maximum %1$s per group is %2$d':
				$polish = 'Maksymalna liczba osób w kategorii „%1$s” to %2$d.';
				break;
			case 'The minimum %1$s per group is %2$d':
				$polish = 'Minimalna liczba osób w kategorii „%1$s” to %2$d.';
				break;
			case 'Sorry, the selected block is not available':
				$polish = 'Ten termin nie jest już dostępny.';
				break;
			// Checkout re-validation after the hold expired (class-wc-booking-cart-manager.php:221).
			case 'Sorry, the selected block is no longer available for %1$s. Please choose another block.':
				$polish = 'Termin „%1$s” nie jest już dostępny. Wybierz inny termin.';
				break;
			default:
				return $translation;
		}

		return $this->is_polish() ? $polish : $translation;
	}

	/**
	 * Plural forms are avoided on purpose ("Wolne miejsca: %d" reads right for 1, 3 and 7),
	 * so one Polish string serves both English forms.
	 *
	 * @param string $translation Translated text.
	 * @param string $single      Singular msgid.
	 * @param string $plural      Plural msgid.
	 * @param int    $number      Count deciding the form.
	 * @return string
	 */
	public function translate_plural( $translation, $single, $plural, $number ) {
		unset( $plural, $number );

		switch ( $single ) {
			case 'There is a maximum of %d place remaining':
				$polish = 'Za mało wolnych miejsc. Wolne miejsca: %d.';
				break;
			case 'There is a maximum of %1$d place remaining on %2$s':
				$polish = 'Za mało wolnych miejsc w dniu %2$s. Wolne miejsca: %1$d.';
				break;
			default:
				return $translation;
		}

		return $this->is_polish() ? $polish : $translation;
	}

	/**
	 * @return bool
	 */
	private function is_polish() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return 0 === strpos( (string) $locale, 'pl' );
	}
}
