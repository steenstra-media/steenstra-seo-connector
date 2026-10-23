<?php
defined( 'ABSPATH' ) || exit;

/**
 * Elementor-pagina's: teksten en beelden lezen en wijzigen in _elementor_data,
 * en een bestaande Elementor-pagina als concept kopiëren (zelfde opmaak).
 *
 * Een veld heet "<element-id>:<pad>", bijv. "3f2a1b9:editor" of "8c1d2e0:tabs.1.tab_content".
 * Alleen tekst- en beeldinstellingen zijn schrijfbaar; opmaak (kleuren, maten, typografie) nooit.
 */
class SSC_Elementor {

	// Vaste lijst tekstinstellingen van Elementor(-Pro)-widgets; al het andere (opmaak) blijft onaangeroerd.
	const TEXT_KEYS = '/^(title|editor|text|description|description_text|title_text|tab_title|tab_content|item_description|html|caption|button_text|heading|sub_heading|subtitle|testimonial_content|testimonial_name|testimonial_job|inner_text|before_text|highlighted_text|rotating_text|after_text|link_text|alert_title|alert_description|ribbon_title|item_text|question|answer)$/';
	const IMAGE_KEYS = array( 'image', 'background_image', 'background_overlay_image' );

	/** Is deze pagina met Elementor gebouwd (en is Elementor actief)? */
	public static function active( $post_id ) {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) && did_action( 'elementor/loaded' );
	}

	public static function data( $post_id ) {
		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/** Alle schrijfbare velden: [ { field, element, widget, value } ]. */
	public static function fields( $post_id ) {
		$out = array();
		self::walk( self::data( $post_id ), function ( $el ) use ( &$out ) {
			$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			$widget   = isset( $el['widgetType'] ) ? $el['widgetType'] : ( isset( $el['elType'] ) ? $el['elType'] : '' );
			foreach ( self::collect( $settings, '' ) as $path => $value ) {
				$out[] = array( 'field' => $el['id'] . ':' . $path, 'element' => $el['id'], 'widget' => $widget, 'value' => $value );
			}
		} );
		return $out;
	}

	private static function walk( array $elements, callable $fn ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) || empty( $el['id'] ) ) {
				continue;
			}
			$fn( $el );
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $fn );
			}
		}
	}

	/** Tekst- en beeldinstellingen, ook in herhaalvelden (tabs, lijsten). */
	private static function collect( array $settings, $prefix ) {
		$out = array();
		foreach ( $settings as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( in_array( $key, self::IMAGE_KEYS, true ) && is_array( $value ) && ! empty( $value['url'] ) ) {
				$out[ $path ] = array( 'id' => isset( $value['id'] ) ? (int) $value['id'] : 0, 'url' => $value['url'] );
			} elseif ( is_string( $value ) && '' !== trim( $value ) && is_string( $key ) && preg_match( self::TEXT_KEYS, $key ) ) {
				$out[ $path ] = $value;
			} elseif ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
				foreach ( $value as $i => $item ) {
					if ( is_array( $item ) ) {
						$out += self::collect( $item, $path . '.' . $i );
					}
				}
			}
		}
		return $out;
	}

	private static function split( $field ) {
		$parts = explode( ':', (string) $field, 2 );
		return 2 === count( $parts ) && '' !== $parts[0] && '' !== $parts[1] ? $parts : null;
	}

	/** Bestaat dit veld, en is het een tekst- of beeldveld? */
	public static function has( $post_id, $field ) {
		foreach ( self::fields( $post_id ) as $f ) {
			if ( $f['field'] === $field ) {
				return true;
			}
		}
		return false;
	}

	public static function get( $post_id, $field ) {
		foreach ( self::fields( $post_id ) as $f ) {
			if ( $f['field'] === $field ) {
				return $f['value'];
			}
		}
		return null;
	}

	/** Eén of meer velden schrijven. $values = [ field => value ]. @return true|WP_Error */
	public static function set_many( $post_id, array $values ) {
		$data    = self::data( $post_id );
		$allowed = wp_list_pluck( self::fields( $post_id ), 'field' );
		foreach ( $values as $field => $value ) {
			if ( ! in_array( $field, $allowed, true ) ) {
				return new WP_Error( 'ssc_elementor_field', 'Elementor-veld niet gevonden of niet schrijfbaar: ' . $field, array( 'status' => 400 ) );
			}
			list( $id, $path ) = self::split( $field );
			$value             = self::clean( $value, $path );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			if ( ! self::replace( $data, $id, explode( '.', $path ), $value ) ) {
				return new WP_Error( 'ssc_elementor_field', 'Elementor-veld kon niet geschreven worden: ' . $field, array( 'status' => 400 ) );
			}
		}
		self::save( $post_id, $data );
		return true;
	}

	public static function set( $post_id, $field, $value ) {
		return self::set_many( $post_id, array( $field => $value ) );
	}

	private static function clean( $value, $path ) {
		$key = substr( $path, strrpos( '.' . $path, '.' ) );
		if ( in_array( $key, self::IMAGE_KEYS, true ) ) {
			$id = is_array( $value ) && isset( $value['id'] ) ? (int) $value['id'] : (int) $value;
			if ( 'attachment' !== get_post_type( $id ) ) {
				return new WP_Error( 'ssc_media', 'Afbeelding niet gevonden.', array( 'status' => 400 ) );
			}
			return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'source' => 'library' );
		}
		return wp_kses_post( (string) $value ); // Elementor slaat HTML ongefilterd op; wij filteren zelf.
	}

	private static function replace( array &$elements, $id, array $path, $value ) {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) && $el['id'] === $id ) {
				$ref = &$el['settings'];
				foreach ( $path as $p ) {
					if ( ! is_array( $ref ) || ! array_key_exists( $p, $ref ) ) {
						return false;
					}
					$ref = &$ref[ $p ];
				}
				$ref = $value;
				return true;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) && self::replace( $el['elements'], $id, $path, $value ) ) {
				return true;
			}
		}
		return false;
	}

	/** Opslaan zoals Elementor het zelf doet, en de gegenereerde CSS en elementcache weggooien. */
	private static function save( $post_id, array $data ) {
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		delete_post_meta( $post_id, '_elementor_page_assets' );
		try {
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		} catch ( \Throwable $e ) {
			// Cache legen mag een wijziging niet laten mislukken.
		}
	}

	/** Kopieer de Elementor-opbouw van $from naar het nieuwe concept $to. @return true|WP_Error */
	public static function clone_to( $from, $to ) {
		if ( ! self::active( $from ) ) {
			return new WP_Error( 'ssc_elementor', 'Modelpagina is geen Elementor-pagina (of Elementor is niet actief).', array( 'status' => 400 ) );
		}
		foreach ( array( '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_elementor_page_settings' ) as $key ) {
			$v = get_post_meta( $from, $key, true );
			if ( '' !== $v && null !== $v ) {
				update_post_meta( $to, $key, is_string( $v ) ? wp_slash( $v ) : $v );
			}
		}
		if ( ! get_post_meta( $to, '_wp_page_template', true ) ) {
			$tpl = get_post_meta( $from, '_wp_page_template', true );
			if ( $tpl ) {
				update_post_meta( $to, '_wp_page_template', $tpl );
			}
		}
		self::copy_layout_meta( $from, $to );
		self::save( $to, self::data( $from ) );
		return true;
	}

	/**
	 * Pagina-instellingen van het thema (indeling, titel, transparante header, zijbalk) van het model overnemen.
	 * Zonder deze meta staat een kopie op de thema-standaard: boxed, met paginatitel en gewone header (The Event Suite, Astra).
	 * Alleen weergave-instellingen van bekende thema's; geen SEO-, Elementor- of interne velden.
	 */
	private static function copy_layout_meta( $from, $to ) {
		$astra_exact = array( 'theme-transparent-header-meta', 'astra-migrate-meta-layouts', 'stick-header-meta', 'adv-header-id-meta', 'footer-sml-layout', 'footer-adv-display' );
		$prefixes    = array( 'ast-', 'site-', 'header-above-', 'header-below-', 'header-main-', '_generate-', '_kad_', 'ocean_' );
		foreach ( get_post_meta( $from ) as $key => $values ) {
			$ok = in_array( $key, $astra_exact, true );
			foreach ( $prefixes as $prefix ) {
				$ok = $ok || 0 === strpos( $key, $prefix );
			}
			if ( ! $ok || 1 !== count( $values ) ) {
				continue;
			}
			$v = maybe_unserialize( $values[0] );
			update_post_meta( $to, $key, is_string( $v ) ? wp_slash( $v ) : $v );
		}
	}
}
