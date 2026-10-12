<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pagina-cache legen na een wijziging, voor de gangbare cacheplugins. Elke aanroep alleen als die plugin er is.
 */
class SSC_Cache {

	public static function purge( $post_id ) {
		$post_id = (int) $post_id;
		try {
			clean_post_cache( $post_id );
			do_action( 'litespeed_purge_post', $post_id );                         // LiteSpeed Cache
			if ( function_exists( 'rocket_clean_post' ) ) { rocket_clean_post( $post_id ); }           // WP Rocket
			if ( function_exists( 'w3tc_flush_post' ) ) { w3tc_flush_post( $post_id ); }               // W3 Total Cache
			if ( function_exists( 'wp_cache_post_change' ) ) { wp_cache_post_change( $post_id ); }     // WP Super Cache
			if ( class_exists( 'WpFastestCache' ) ) { do_action( 'wpfc_clear_post_cache_by_id', false, $post_id ); } // WP Fastest Cache
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache( get_permalink( $post_id ) ); } // SiteGround
			do_action( 'breeze_clear_all_cache' );                                   // Breeze (Cloudways)
			do_action( 'ssc_purge_post', $post_id );                                 // eigen haak voor uitzonderingen
		} catch ( \Throwable $e ) {
			// Cache legen mag nooit een wijziging laten mislukken.
		}
	}
}
