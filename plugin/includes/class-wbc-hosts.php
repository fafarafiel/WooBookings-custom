<?php
/**
 * Host registry. One shared list of the people who lead sessions.
 *
 * A custom post type carries this, not a taxonomy. WPML translates taxonomy terms, which splits
 * the key that the assignment map relies on, and a person's name is meant to be one string across
 * every language.
 *
 * The editing model deliberately mirrors Posts: Hosts, Add new, title is the name, editor is the
 * biography, Publish. No new concept for the person maintaining the site.
 *
 * @package WooBookings_Custom
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the host post type and reads people for the grid payload.
 */
final class WBC_Hosts {

	const POST_TYPE = 'wbc_host';

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		// The "Featured image" box exists only when the theme supports thumbnails. Most themes do,
		// but switching themes (or a theme limiting support to post/page) would silently remove the
		// box, so support is added for our own type after the theme (priority 11). add_theme_support
		// with an array of types ADDS to the types already registered.
		add_action( 'after_setup_theme', array( $this, 'ensure_thumbnail_support' ), 11 );
	}

	/**
	 * @return void
	 */
	public function ensure_thumbnail_support() {
		add_theme_support( 'post-thumbnails', array( self::POST_TYPE ) );
	}

	/**
	 * No front end: `public => false` with `show_ui => true`. A single host has no page of its
	 * own because the biography is shown in the grid modal, so there is no reason to mint public
	 * permalinks or sitemap entries.
	 *
	 * @return void
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Hosts', 'woobookings-custom' ),
					'singular_name'      => __( 'Host', 'woobookings-custom' ),
					'add_new'            => __( 'Add new', 'woobookings-custom' ),
					'add_new_item'       => __( 'Add host', 'woobookings-custom' ),
					'edit_item'          => __( 'Edit host', 'woobookings-custom' ),
					'new_item'           => __( 'New host', 'woobookings-custom' ),
					'search_items'       => __( 'Search hosts', 'woobookings-custom' ),
					'not_found'          => __( 'No hosts yet', 'woobookings-custom' ),
					'all_items'          => __( 'Hosts', 'woobookings-custom' ),
					'menu_name'          => __( 'Hosts', 'woobookings-custom' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-groups',
				/*
				 * `thumbnail` is the "Featured image" box in the editor, the only channel for a
				 * host's photo. The biography goes through wp_strip_all_tags, so an image pasted
				 * into the text never reaches the dialog; the photo has its own field, like the
				 * product image.
				 */
				'supports'            => array( 'title', 'editor', 'thumbnail', 'revisions' ),
				'capability_type'     => 'post',
				/*
				 * Block editor for the biography, the same one used for pages. Note: `public => false`
				 * does NOT close the REST route. WordPress core serves every PUBLISHED post of a
				 * `show_in_rest` type without login (only `context=edit` needs capabilities). Name,
				 * biography and featured image are therefore readable anonymously through the REST
				 * API, which is the same data the grid shows publicly anyway; drafts stay private.
				 * Do not put anything here that is not meant for the website.
				 */
				'show_in_rest'        => true,
				'rest_base'           => 'wbc-hostowie',
			)
		);
	}

	/**
	 * Every published host, for the select in the product editor.
	 *
	 * `suppress_filters` with no language argument: the registry is single-language by design,
	 * like product discovery. Without it WPML would filter the list away on a translated screen.
	 *
	 * @return array<int,string> Post ID to display name.
	 */
	public function get_choices() {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$title = trim( (string) $post->post_title );
			if ( '' !== $title ) {
				$out[ (int) $post->ID ] = $title;
			}
		}

		return $out;
	}

	/**
	 * Name and biography of one person.
	 *
	 * Reads the post directly through `get_post()` rather than WP_Query, so it never passes
	 * through WPML's language filters and resolves to the same person in every language.
	 *
	 * @param int $host_id Host post ID.
	 * @return array{name:string,bio:string,image:array|null}|null Null when the post is missing or trashed.
	 */
	public function get_host( $host_id ) {
		$host_id = (int) $host_id;
		if ( $host_id <= 0 ) {
			return null;
		}

		$post = get_post( $host_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		$name = trim( (string) $post->post_title );
		if ( '' === $name ) {
			return null;
		}

		return array(
			'name'  => $name,
			'bio'   => trim( wp_strip_all_tags( (string) $post->post_content ) ),
			'image' => WBC_Image::payload( get_post_thumbnail_id( $post ) ),
		);
	}
}
