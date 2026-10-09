<?php
defined( 'ABSPATH' ) || exit;

/**
 * ACF-velden lezen en schrijven. Doet niets als ACF niet actief is.
 */
class SSC_ACF {

	public static function active() {
		return function_exists( 'get_field' ) && function_exists( 'update_field' ) && function_exists( 'acf_get_field_groups' );
	}

	/** Ruwe waarden (zoals ACF ze opslaat), geschikt om terug te schrijven. */
	public static function values( $post_id ) {
		if ( ! self::active() ) {
			return null;
		}
		$values = get_fields( $post_id, false );
		return $values ? $values : array();
	}

	/** Opbouw van alle veldgroepen die bij deze pagina horen, inclusief layouts van flexibele content. */
	public static function schema( $post_id ) {
		if ( ! self::active() ) {
			return null;
		}
		$groups = acf_get_field_groups( array( 'post_id' => (int) $post_id ) );
		$out    = array();
		foreach ( $groups as $group ) {
			$out[] = array(
				'key'    => $group['key'],
				'title'  => $group['title'],
				'fields' => self::describe_fields( acf_get_fields( $group ) ),
			);
		}
		return $out;
	}

	private static function describe_fields( $fields ) {
		$out = array();
		foreach ( (array) $fields as $field ) {
			$item = array(
				'key'   => $field['key'],
				'name'  => $field['name'],
				'label' => $field['label'],
				'type'  => $field['type'],
			);
			if ( ! empty( $field['instructions'] ) ) {
				$item['instructions'] = $field['instructions'];
			}
			if ( ! empty( $field['choices'] ) ) {
				$item['choices'] = $field['choices'];
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$item['sub_fields'] = self::describe_fields( $field['sub_fields'] );
			}
			if ( 'flexible_content' === $field['type'] && ! empty( $field['layouts'] ) ) {
				$item['layouts'] = array();
				foreach ( $field['layouts'] as $layout ) {
					$item['layouts'][] = array(
						'name'       => $layout['name'],
						'label'      => $layout['label'],
						'sub_fields' => self::describe_fields( isset( $layout['sub_fields'] ) ? $layout['sub_fields'] : array() ),
					);
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Zoekt de veldsleutel bij een veldnaam. Nodig voor pagina's waar het veld
	 * nog geen waarde heeft (ACF herkent een naam dan niet).
	 */
	public static function resolve_key( $selector, $post_id ) {
		if ( 0 === strpos( (string) $selector, 'field_' ) ) {
			return $selector;
		}
		$object = get_field_object( $selector, $post_id, false, false );
		if ( $object && ! empty( $object['key'] ) ) {
			return $object['key'];
		}
		foreach ( acf_get_field_groups( array( 'post_id' => (int) $post_id ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( $field['name'] === $selector ) {
					return $field['key'];
				}
			}
		}
		return null;
	}

	public static function get( $selector, $post_id ) {
		if ( ! self::active() ) {
			return null;
		}
		$key = self::resolve_key( $selector, $post_id );
		return $key ? get_field( $key, $post_id, false ) : null;
	}

	/** @return true|WP_Error */
	public static function set( $selector, $value, $post_id ) {
		if ( ! self::active() ) {
			return new WP_Error( 'ssc_acf', 'ACF is niet actief op deze site.', array( 'status' => 409 ) );
		}
		$key = self::resolve_key( $selector, $post_id );
		if ( ! $key ) {
			return new WP_Error( 'ssc_acf_field', 'ACF-veld niet gevonden voor deze pagina: ' . $selector, array( 'status' => 400 ) );
		}
		update_field( $key, $value, $post_id );
		clean_post_cache( $post_id );
		return true;
	}
}
