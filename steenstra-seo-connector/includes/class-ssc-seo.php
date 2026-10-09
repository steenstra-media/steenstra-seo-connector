<?php
defined( 'ABSPATH' ) || exit;

/**
 * SEO-velden lezen en schrijven voor Yoast SEO en Rank Math.
 * Velden: title, description, canonical, noindex (bool), focus_keyword.
 */
class SSC_SEO {

	const FIELDS = array( 'title', 'description', 'canonical', 'noindex', 'focus_keyword' );

	public static function plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		return 'none';
	}

	private static function keys() {
		if ( 'yoast' === self::plugin() ) {
			return array(
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'canonical'     => '_yoast_wpseo_canonical',
				'noindex'       => '_yoast_wpseo_meta-robots-noindex',
				'focus_keyword' => '_yoast_wpseo_focuskw',
			);
		}
		if ( 'rankmath' === self::plugin() ) {
			return array(
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'canonical'     => 'rank_math_canonical_url',
				'noindex'       => 'rank_math_robots',
				'focus_keyword' => 'rank_math_focus_keyword',
			);
		}
		return array();
	}

	public static function get( $post_id ) {
		$out  = array( 'plugin' => self::plugin() );
		$keys = self::keys();
		foreach ( self::FIELDS as $field ) {
			$out[ $field ] = isset( $keys[ $field ] ) ? self::read( $post_id, $field, $keys[ $field ] ) : null;
		}
		return $out;
	}

	private static function read( $post_id, $field, $meta_key ) {
		$value = get_post_meta( $post_id, $meta_key, true );
		if ( 'noindex' === $field ) {
			if ( 'rankmath' === self::plugin() ) {
				return is_array( $value ) && in_array( 'noindex', $value, true );
			}
			return '1' === (string) $value;
		}
		return '' === $value ? null : (string) $value;
	}

	/** @return true|WP_Error */
	public static function set( $post_id, $field, $value ) {
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return new WP_Error( 'ssc_seo_field', 'Onbekend SEO-veld: ' . $field, array( 'status' => 400 ) );
		}
		$keys = self::keys();
		if ( empty( $keys ) ) {
			return new WP_Error( 'ssc_seo_plugin', 'Geen ondersteunde SEO-plugin actief (Yoast of Rank Math).', array( 'status' => 409 ) );
		}
		$meta_key = $keys[ $field ];

		if ( 'noindex' === $field ) {
			$noindex = (bool) $value;
			if ( 'rankmath' === self::plugin() ) {
				$robots = get_post_meta( $post_id, $meta_key, true );
				$robots = is_array( $robots ) ? array_values( array_diff( $robots, array( 'index', 'noindex' ) ) ) : array();
				$robots[] = $noindex ? 'noindex' : 'index';
				update_post_meta( $post_id, $meta_key, $robots );
			} elseif ( $noindex ) {
				update_post_meta( $post_id, $meta_key, '1' );
			} else {
				delete_post_meta( $post_id, $meta_key );
			}
		} elseif ( null === $value || '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			$value = 'canonical' === $field ? esc_url_raw( (string) $value ) : sanitize_text_field( (string) $value );
			update_post_meta( $post_id, $meta_key, $value );
		}

		self::refresh( $post_id );
		return true;
	}

	/** Yoast werkt met "indexables"; die moeten na een meta-wijziging opnieuw opgebouwd worden. */
	public static function refresh( $post_id ) {
		clean_post_cache( $post_id );
		if ( 'yoast' !== self::plugin() || ! function_exists( 'YoastSEO' ) ) {
			return;
		}
		try {
			$repo    = YoastSEO()->classes->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );
			$builder = YoastSEO()->classes->get( 'Yoast\WP\SEO\Builders\Indexable_Builder' );
			$current = $repo->find_by_id_and_type( $post_id, 'post', false );
			$builder->build_for_id_and_type( $post_id, 'post', $current ? $current : false );
		} catch ( \Throwable $e ) {
			// Niet fataal: Yoast bouwt de indexable later alsnog op.
		}
	}
}
