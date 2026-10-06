<?php
/**
 * Image payload — one shape for every picture the grid modal can show.
 *
 * The modal is text-only by design (descriptions and bios go through wp_strip_all_tags and land
 * via textContent — no HTML from the editor ever reaches the page). A picture therefore needs
 * its own channel: the product image for a session, the featured image for a host.
 * Both are attachments, so both resolve through this one helper and the front sees a single
 * {src, srcset, alt, width, height} record — or null, which the JS reads as "no picture".
 *
 * The editor's mental model: "Product image" and "Featured image", the boxes WordPress already
 * has. Nothing pasted into the description counts.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an attachment into the modal image record.
 */
final class WBC_Image {

	/*
	 * `medium_large` (768px wide by default) covers the 472px-wide dialog body at 1x and most of
	 * it at 2x; `srcset` from the same attachment lets the browser go larger on dense screens
	 * without shipping a 1920px original to a phone.
	 */
	const SIZE = 'medium_large';

	/**
	 * @param int $attachment_id Attachment (post) ID, 0 when the product/host has no image.
	 * @return array{src:string,srcset:string,alt:string,width:int,height:int}|null
	 */
	public static function payload( $attachment_id ) {
		// Per-request memo: the same person returns in the payload for every slot of every product
		// (default plus overrides), and src/srcset are computed from metadata each time.
		static $memo = array();

		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $attachment_id, $memo ) ) {
			return $memo[ $attachment_id ];
		}

		$src = wp_get_attachment_image_src( $attachment_id, self::SIZE );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			$memo[ $attachment_id ] = null;
			return null;
		}

		$srcset = wp_get_attachment_image_srcset( $attachment_id, self::SIZE );
		$alt    = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		$memo[ $attachment_id ] = array(
			'src'    => (string) $src[0],
			'srcset' => is_string( $srcset ) ? $srcset : '',
			'alt'    => trim( wp_strip_all_tags( (string) $alt ) ),
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		);
		return $memo[ $attachment_id ];
	}
}
