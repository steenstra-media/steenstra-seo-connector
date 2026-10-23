<?php
defined( 'ABSPATH' ) || exit;

/**
 * Wijzigingen aan bestaande pagina's. Elke wijziging wordt eerst als voorstel
 * opgeslagen; pas bij "toepassen" komt ze op de site. Toegepaste wijzigingen
 * zijn terug te draaien met de bewaarde oude waarde.
 *
 * Types: title, content, acf, seo, schema, featured_media, elementor (veld "<element-id>:<pad>").
 */
class SSC_Changes {

	const TYPES = array( 'title', 'content', 'acf', 'seo', 'schema', 'featured_media', 'elementor' );

	/** @return array|WP_Error */
	public static function propose( $post_id, $type, $field, $value, $note = '' ) {
		global $wpdb;
		$valid = self::validate( $post_id, $type, $field );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			SSC_Setup::table(),
			array(
				'post_id'    => (int) $post_id,
				'type'       => $type,
				'field'      => (string) $field,
				'old_value'  => wp_json_encode( self::current( $post_id, $type, $field ) ),
				'new_value'  => wp_json_encode( $value ),
				'status'     => 'proposed',
				'note'       => (string) $note,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/** @return array|WP_Error */
	public static function apply( $id ) {
		$change = self::row( $id );
		if ( ! $change ) {
			return new WP_Error( 'ssc_change', 'Wijziging niet gevonden.', array( 'status' => 404 ) );
		}
		if ( 'proposed' !== $change->status ) {
			return new WP_Error( 'ssc_change_status', 'Alleen voorstellen kunnen toegepast worden (status: ' . $change->status . ').', array( 'status' => 409 ) );
		}
		// Oude waarde opnieuw vastleggen op het moment van toepassen.
		$old    = self::current( $change->post_id, $change->type, $change->field );
		$result = self::write( $change->post_id, $change->type, $change->field, json_decode( $change->new_value, true ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::update( $id, array( 'status' => 'applied', 'old_value' => wp_json_encode( $old ) ) );
		SSC_Cache::purge( $change->post_id );
		return self::get( $id );
	}

	/** @return array|WP_Error */
	public static function revert( $id ) {
		$change = self::row( $id );
		if ( ! $change ) {
			return new WP_Error( 'ssc_change', 'Wijziging niet gevonden.', array( 'status' => 404 ) );
		}
		if ( 'applied' !== $change->status ) {
			return new WP_Error( 'ssc_change_status', 'Alleen toegepaste wijzigingen kunnen teruggedraaid worden.', array( 'status' => 409 ) );
		}
		$result = self::write( $change->post_id, $change->type, $change->field, json_decode( $change->old_value, true ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::update( $id, array( 'status' => 'reverted' ) );
		SSC_Cache::purge( $change->post_id );
		return self::get( $id );
	}

	/** @return array|WP_Error */
	public static function reject( $id ) {
		$change = self::row( $id );
		if ( ! $change ) {
			return new WP_Error( 'ssc_change', 'Wijziging niet gevonden.', array( 'status' => 404 ) );
		}
		if ( 'proposed' !== $change->status ) {
			return new WP_Error( 'ssc_change_status', 'Alleen voorstellen kunnen afgewezen worden.', array( 'status' => 409 ) );
		}
		self::update( $id, array( 'status' => 'rejected' ) );
		return self::get( $id );
	}

	public static function query( $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['post_id'] ) ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $args['post_id'];
		}
		$limit    = empty( $args['limit'] ) ? 50 : min( 200, max( 1, (int) $args['limit'] ) );
		$params[] = $limit;
		$sql      = 'SELECT * FROM ' . SSC_Setup::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'format' ), (array) $rows );
	}

	public static function get( $id ) {
		$row = self::row( $id );
		return $row ? self::format( $row ) : null;
	}

	private static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SSC_Setup::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function update( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( SSC_Setup::table(), $data, array( 'id' => (int) $id ) );
	}

	public static function format( $row ) {
		return array(
			'id'         => (int) $row->id,
			'post_id'    => (int) $row->post_id,
			'post_title' => get_the_title( (int) $row->post_id ),
			'type'       => $row->type,
			'field'      => $row->field,
			'old_value'  => json_decode( $row->old_value, true ),
			'new_value'  => json_decode( $row->new_value, true ),
			'status'     => $row->status,
			'note'       => $row->note,
			'created_at' => $row->created_at,
			'updated_at' => $row->updated_at,
		);
	}

	/** @return true|WP_Error */
	private static function validate( $post_id, $type, $field ) {
		$post = get_post( $post_id );
		if ( ! $post || in_array( $post->post_type, array( 'attachment', 'revision', 'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ), true ) ) {
			return new WP_Error( 'ssc_post', 'Pagina niet gevonden of niet bewerkbaar via deze koppeling.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'ssc_forbidden', 'Geen rechten voor deze pagina.', array( 'status' => 403 ) );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'ssc_type', 'Onbekend wijzigingstype: ' . $type, array( 'status' => 400 ) );
		}
		if ( 'seo' === $type && ! in_array( $field, SSC_SEO::FIELDS, true ) ) {
			return new WP_Error( 'ssc_seo_field', 'Onbekend SEO-veld: ' . $field, array( 'status' => 400 ) );
		}
		if ( 'acf' === $type && ( ! SSC_ACF::active() || ! SSC_ACF::resolve_key( $field, $post_id ) ) ) {
			return new WP_Error( 'ssc_acf_field', 'ACF-veld niet gevonden voor deze pagina: ' . $field, array( 'status' => 400 ) );
		}
		if ( 'elementor' === $type && ( ! SSC_Elementor::active( $post_id ) || ! SSC_Elementor::has( $post_id, $field ) ) ) {
			return new WP_Error( 'ssc_elementor_field', 'Elementor-veld niet gevonden voor deze pagina: ' . $field, array( 'status' => 400 ) );
		}
		if ( 'content' === $type && SSC_Elementor::active( $post_id ) ) {
			return new WP_Error( 'ssc_elementor_content', 'Deze pagina is met Elementor gebouwd: wijzig de tekst via type "elementor", niet via content.', array( 'status' => 400 ) );
		}
		return true;
	}

	private static function current( $post_id, $type, $field ) {
		switch ( $type ) {
			case 'title':
				return get_post_field( 'post_title', $post_id, 'raw' );
			case 'content':
				return get_post_field( 'post_content', $post_id, 'raw' );
			case 'acf':
				return SSC_ACF::get( $field, $post_id );
			case 'seo':
				$seo = SSC_SEO::get( $post_id );
				return isset( $seo[ $field ] ) ? $seo[ $field ] : null;
			case 'schema':
				return SSC_Schema::get( $post_id );
			case 'featured_media':
				return (int) get_post_thumbnail_id( $post_id );
			case 'elementor':
				return SSC_Elementor::get( $post_id, $field );
		}
		return null;
	}

	/** @return true|WP_Error */
	private static function write( $post_id, $type, $field, $value ) {
		switch ( $type ) {
			case 'title':
				$r = wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( (string) $value ) ), true );
				return is_wp_error( $r ) ? $r : true;
			case 'content':
				$r = self::save_post( array( 'ID' => $post_id, 'post_content' => (string) $value ) );
				return is_wp_error( $r ) ? $r : true;
			case 'acf':
				return SSC_ACF::set( $field, $value, $post_id );
			case 'seo':
				return SSC_SEO::set( $post_id, $field, $value );
			case 'schema':
				return SSC_Schema::set( $post_id, $value );
			case 'featured_media':
				if ( ! $value ) {
					delete_post_thumbnail( $post_id );
					return true;
				}
				if ( 'attachment' !== get_post_type( (int) $value ) ) {
					return new WP_Error( 'ssc_media', 'Afbeelding niet gevonden.', array( 'status' => 400 ) );
				}
				set_post_thumbnail( $post_id, (int) $value );
				return true;
			case 'elementor':
				return SSC_Elementor::set( $post_id, $field, $value );
		}
		return new WP_Error( 'ssc_type', 'Onbekend wijzigingstype.', array( 'status' => 400 ) );
	}

	/**
	 * Bericht opslaan (bijwerken of nieuw) met behoud van JSON-LD-scripts in de inhoud.
	 *
	 * WordPress filtert bij opslaan alle HTML volgens de rechten van de API-gebruiker; zonder unfiltered_html
	 * verdwijnt een <script type="application/ld+json"> (FAQ-schema in een HTML-blok) en blijft de JSON als tekst
	 * op de pagina staan. Hier gaat de inhoud nog steeds door wp_kses_post, maar JSON-LD-scripts worden vooraf
	 * apart gezet en daarna teruggeplaatst. Andere scripts worden geweigerd. wp_slash: wp_update_post/wp_insert_post
	 * doen wp_unslash, anders verdwijnen backslashes in blok-JSON.
	 *
	 * @return int|WP_Error post-ID
	 */
	public static function save_post( array $postarr ) {
		$content = isset( $postarr['post_content'] ) ? (string) $postarr['post_content'] : '';
		$scripts = array();
		$error   = null;
		$masked  = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script\s*>#is',
			function ( $m ) use ( &$scripts, &$error ) {
				if ( ! preg_match( '#\btype\s*=\s*["\']application/ld\+json["\']#i', $m[1] ) ) {
					$error = new WP_Error( 'ssc_script', 'Alleen JSON-LD-scripts (type="application/ld+json") zijn toegestaan in de inhoud.', array( 'status' => 400 ) );
				} elseif ( null === json_decode( trim( $m[2] ) ) ) {
					$error = new WP_Error( 'ssc_script_json', 'Een JSON-LD-script in de inhoud bevat geen geldige JSON.', array( 'status' => 400 ) );
				}
				$key             = '<!-- ssc-jsonld-' . count( $scripts ) . ' -->';
				$scripts[ $key ] = '<script type="application/ld+json">' . trim( $m[2] ) . '</script>';
				return $key;
			},
			$content
		);
		if ( $error ) {
			return $error;
		}
		if ( preg_match( '#<script\b#i', $masked ) ) {
			return new WP_Error( 'ssc_script', 'Onvolledige script-tag in de inhoud.', array( 'status' => 400 ) );
		}
		$kses = false;
		if ( $scripts ) {
			$postarr['post_content'] = strtr( wp_kses_post( $masked ), $scripts );
			$kses                    = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
			if ( $kses ) {
				kses_remove_filters();
			}
		}
		$r = empty( $postarr['ID'] ) ? wp_insert_post( wp_slash( $postarr ), true ) : wp_update_post( wp_slash( $postarr ), true );
		if ( $kses ) {
			kses_init_filters();
		}
		return $r;
	}

	/** Voor nieuwe conceptpagina's: direct schrijven zonder voorstel (concept staat niet live). */
	public static function write_direct( $post_id, $type, $field, $value ) {
		return self::write( $post_id, $type, $field, $value );
	}
}
