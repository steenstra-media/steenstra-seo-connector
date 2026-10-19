<?php
defined( 'ABSPATH' ) || exit;

/**
 * Voorbeeldlinks zonder login voor eigen concepten: ?ssc_preview=<token>.
 * Token is geheim, 7 dagen geldig, alleen voor pagina's die via de koppeling zijn aangemaakt.
 * De pagina wordt nooit gecachet en krijgt noindex.
 */
class SSC_Preview {

	const META_TOKEN   = '_ssc_preview_token';
	const META_EXPIRES = '_ssc_preview_expires';
	const DAYS         = 7;

	public static function init() {
		if ( empty( $_GET['ssc_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		add_action( 'pre_get_posts', array( __CLASS__, 'allow_drafts' ) );
		add_filter( 'redirect_canonical', '__return_false' ); // anders stuurt WordPress concept-URL's door naar de homepage
		add_filter( 'posts_results', array( __CLASS__, 'check_token' ), 10, 2 );
	}

	public static function create( $post_id ) {
		$token = wp_generate_password( 40, false, false );
		update_post_meta( $post_id, self::META_TOKEN, wp_hash( $token ) );
		update_post_meta( $post_id, self::META_EXPIRES, time() + self::DAYS * DAY_IN_SECONDS );
		$param = 'page' === get_post_type( $post_id ) ? 'page_id' : 'p';
		return array(
			'url'     => add_query_arg( array( $param => $post_id, 'ssc_preview' => $token ), home_url( '/' ) ),
			'expires' => gmdate( 'c', time() + self::DAYS * DAY_IN_SECONDS ),
		);
	}

	public static function allow_drafts( $query ) {
		if ( ! $query->is_main_query() || is_admin() ) {
			return;
		}
		$query->set( 'post_status', array( 'publish', 'draft', 'pending', 'future' ) );
	}

	public static function check_token( $posts, $query ) {
		if ( ! $query->is_main_query() || is_admin() || count( $posts ) !== 1 ) {
			return $posts;
		}
		$post  = $posts[0];
		$token = sanitize_text_field( wp_unslash( $_GET['ssc_preview'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$hash  = get_post_meta( $post->ID, self::META_TOKEN, true );
		$until = (int) get_post_meta( $post->ID, self::META_EXPIRES, true );
		if ( 'publish' === $post->post_status ) {
			return $posts;
		}
		if ( ! $hash || ! hash_equals( $hash, wp_hash( $token ) ) || $until < time() || ! get_post_meta( $post->ID, '_ssc_created', true ) ) {
			return array(); // geen geldig token: concept blijft onzichtbaar (404)
		}
		$post->post_status = 'publish'; // alleen in het geheugen, voor deze weergave
		if ( function_exists( 'ssc_no_cache' ) ) {
			ssc_no_cache();
		}
		add_filter( 'wp_robots', function ( $robots ) { $robots['noindex'] = true; $robots['nofollow'] = true; return $robots; } );
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		return array( $post );
	}
}
