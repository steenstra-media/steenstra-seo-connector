<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schema.org (JSON-LD) per pagina. Ondersteund: FAQ.
 * Wordt alleen uitgegeven op gepubliceerde pagina's waar via de API schema is gezet.
 * Bij Yoast/Rank Math wordt het ingehaakt in hun schema-graph; anders een eigen script-tag.
 */
class SSC_Schema {

	const META_KEY = '_ssc_schema';

	public static function get( $post_id ) {
		$value = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $value ) ? $value : null;
	}

	/** @return true|WP_Error */
	public static function set( $post_id, $value ) {
		if ( empty( $value ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return true;
		}
		$clean = self::sanitize( $value );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		update_post_meta( $post_id, self::META_KEY, $clean );
		return true;
	}

	private static function sanitize( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'ssc_schema', 'Schema moet een object zijn, bijv. {"faq":[{"q":"...","a":"..."}]}.', array( 'status' => 400 ) );
		}
		$out = array();
		if ( ! empty( $value['faq'] ) && is_array( $value['faq'] ) ) {
			$out['faq'] = array();
			foreach ( $value['faq'] as $item ) {
				if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
					continue;
				}
				$out['faq'][] = array(
					'q' => sanitize_text_field( (string) $item['q'] ),
					'a' => wp_kses( (string) $item['a'], array( 'a' => array( 'href' => true ), 'strong' => array(), 'em' => array(), 'br' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array() ) ),
				);
			}
		}
		if ( empty( $out ) ) {
			return new WP_Error( 'ssc_schema', 'Geen geldig schema gevonden (ondersteund: faq).', array( 'status' => 400 ) );
		}
		return $out;
	}

	public static function faq_piece( $post_id, $faq ) {
		$url = get_permalink( $post_id );
		return array(
			'@type'      => 'FAQPage',
			'@id'        => $url . '#ssc-faq',
			'url'        => $url,
			'mainEntity' => array_map(
				function ( $item ) {
					return array(
						'@type'          => 'Question',
						'name'           => $item['q'],
						'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $item['a'] ),
					);
				},
				$faq
			),
		);
	}

	public static function current_faq() {
		if ( ! is_singular() ) {
			return null;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return null;
		}
		$schema = self::get( $post_id );
		return empty( $schema['faq'] ) ? null : array( $post_id, $schema['faq'] );
	}

	public static function register_output() {
		// Yoast: toevoegen aan de bestaande graph, tenzij er al een FAQPage in zit.
		add_filter(
			'wpseo_schema_graph',
			function ( $graph ) {
				try {
					$faq = SSC_Schema::current_faq();
					if ( ! $faq || ! is_array( $graph ) ) {
						return $graph;
					}
					foreach ( $graph as $piece ) {
						$types = isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
						if ( in_array( 'FAQPage', $types, true ) ) {
							return $graph;
						}
					}
					$graph[] = SSC_Schema::faq_piece( $faq[0], $faq[1] );
				} catch ( \Throwable $e ) {
					return $graph;
				}
				return $graph;
			},
			20
		);

		// Rank Math.
		add_filter(
			'rank_math/json_ld',
			function ( $data ) {
				try {
					$faq = SSC_Schema::current_faq();
					if ( ! $faq || ! is_array( $data ) ) {
						return $data;
					}
					foreach ( $data as $piece ) {
						$types = is_array( $piece ) && isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
						if ( in_array( 'FAQPage', $types, true ) ) {
							return $data;
						}
					}
					$data['ssc_faq'] = SSC_Schema::faq_piece( $faq[0], $faq[1] );
				} catch ( \Throwable $e ) {
					return $data;
				}
				return $data;
			},
			99
		);

		// Geen SEO-plugin: eigen script-tag.
		add_action(
			'wp_head',
			function () {
				try {
					if ( 'none' !== SSC_SEO::plugin() ) {
						return;
					}
					$faq = SSC_Schema::current_faq();
					if ( ! $faq ) {
						return;
					}
					$piece             = SSC_Schema::faq_piece( $faq[0], $faq[1] );
					$piece['@context'] = 'https://schema.org';
					echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $piece, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
				} catch ( \Throwable $e ) {
					return;
				}
			},
			99
		);
	}
}
