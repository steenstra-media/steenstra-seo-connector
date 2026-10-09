<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST-routes onder /wp-json/steenstra-seo/v1. Alle routes vereisen een ondertekende aanvraag.
 */
class SSC_API {

	const OPTION_REQUIRE_REVIEW = 'ssc_require_review';

	public static function register_routes() {
		$id = '(?P<id>\d+)';

		self::route( '/status', 'GET', 'status' );
		self::route( '/pages', 'GET', 'list_pages' );
		self::route( '/pages', 'POST', 'create_page' );
		self::route( "/pages/{$id}", 'GET', 'get_page' );
		self::route( "/pages/{$id}/acf-schema", 'GET', 'acf_schema' );
		self::route( "/pages/{$id}/changes", 'POST', 'add_changes' );
		self::route( '/changes', 'GET', 'list_changes' );
		self::route( "/changes/{$id}/apply", 'POST', 'apply_change' );
		self::route( "/changes/{$id}/revert", 'POST', 'revert_change' );
		self::route( "/changes/{$id}/reject", 'POST', 'reject_change' );
		self::route( "/pages/{$id}/publish", 'POST', 'publish_page' );
		self::route( "/pages/{$id}/trash", 'POST', 'trash_page' );
		self::route( '/media', 'POST', 'upload_media' );
	}

	private static function route( $path, $method, $handler ) {
		register_rest_route(
			SSC_NAMESPACE,
			$path,
			array(
				'methods'             => $method,
				'permission_callback' => array( 'SSC_Auth', 'permission' ),
				'callback'            => function ( WP_REST_Request $request ) use ( $handler ) {
					try {
						$result = call_user_func( array( 'SSC_API', $handler ), $request );
					} catch ( \Throwable $e ) {
						return new WP_Error( 'ssc_error', 'Interne fout: ' . $e->getMessage(), array( 'status' => 500 ) );
					}
					return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
				},
			)
		);
	}

	public static function require_review() {
		// Standaard uit: wijzigingen gaan direct live (gelogd en terug te draaien). Aan te zetten onder Extra/Tools → Steenstra SEO.
		return '1' === (string) get_option( self::OPTION_REQUIRE_REVIEW, '0' );
	}

	/* ---------- status ---------- */

	public static function status() {
		global $wp_version;
		$theme = wp_get_theme();
		return array(
			'connector_version' => SSC_VERSION,
			'site'              => home_url(),
			'name'              => get_bloginfo( 'name' ),
			'language'          => get_locale(),
			'wordpress'         => $wp_version,
			'php'               => PHP_VERSION,
			'theme'             => array( 'name' => $theme->get( 'Name' ), 'stylesheet' => get_stylesheet(), 'template' => get_template() ),
			'seo_plugin'        => SSC_SEO::plugin(),
			'acf'               => SSC_ACF::active() ? ( defined( 'ACF_VERSION' ) ? ACF_VERSION : 'actief' ) : null,
			'acf_pro'           => defined( 'ACF_PRO' ) ? true : false,
			'wpml'              => defined( 'ICL_SITEPRESS_VERSION' ),
			'woocommerce'       => class_exists( 'WooCommerce' ),
			'blog_public'       => '1' === (string) get_option( 'blog_public' ),
			'permalinks'        => get_option( 'permalink_structure' ),
			'require_review'    => self::require_review(),
			'api_user'          => SSC_USER_LOGIN,
			'post_types'        => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
		);
	}

	/* ---------- pagina's ---------- */

	private static function allowed_type( $type ) {
		$public = get_post_types( array( 'public' => true ), 'names' );
		unset( $public['attachment'] );
		return isset( $public[ $type ] );
	}

	public static function list_pages( WP_REST_Request $request ) {
		$type = sanitize_key( $request->get_param( 'type' ) ? $request->get_param( 'type' ) : 'page' );
		if ( ! self::allowed_type( $type ) ) {
			return new WP_Error( 'ssc_type', 'Onbekend of niet-openbaar berichttype: ' . $type, array( 'status' => 400 ) );
		}
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : 100 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ? $request->get_param( 'page' ) : 1 ) );
		$query    = new WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				's'              => $request->get_param( 'search' ) ? sanitize_text_field( $request->get_param( 'search' ) ) : '',
				'no_found_rows'  => false,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::summary( $post );
		}
		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'items'       => $items,
		);
	}

	private static function summary( WP_Post $post ) {
		return array(
			'id'            => $post->ID,
			'type'          => $post->post_type,
			'title'         => $post->post_title,
			'slug'          => $post->post_name,
			'status'        => $post->post_status,
			'link'          => get_permalink( $post ),
			'parent'        => (int) $post->post_parent,
			'menu_order'    => (int) $post->menu_order,
			'template'      => get_page_template_slug( $post ),
			'modified'      => $post->post_modified_gmt,
			'content_words' => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			'seo'           => SSC_SEO::get( $post->ID ),
		);
	}

	public static function get_page( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ! self::allowed_type( $post->post_type ) ) {
			return new WP_Error( 'ssc_post', 'Pagina niet gevonden.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'ssc_forbidden', 'Geen rechten voor deze pagina.', array( 'status' => 403 ) );
		}
		$data                   = self::summary( $post );
		$data['content']        = $post->post_content;
		$data['excerpt']        = $post->post_excerpt;
		$data['featured_media'] = (int) get_post_thumbnail_id( $post );
		$data['acf']            = SSC_ACF::values( $post->ID );
		$data['schema']         = SSC_Schema::get( $post->ID );
		$data['preview_link']   = get_preview_post_link( $post );
		$data['edit_link']      = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
		return $data;
	}

	public static function acf_schema( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post ) {
			return new WP_Error( 'ssc_post', 'Pagina niet gevonden.', array( 'status' => 404 ) );
		}
		if ( ! SSC_ACF::active() ) {
			return new WP_Error( 'ssc_acf', 'ACF is niet actief op deze site.', array( 'status' => 409 ) );
		}
		return array( 'post_id' => $post->ID, 'groups' => SSC_ACF::schema( $post->ID ) );
	}

	/**
	 * Nieuwe pagina, altijd als concept. Publiceren gebeurt handmatig in WordPress.
	 * Body: title*, type, slug, parent, template, menu_order, content, excerpt, acf{}, seo{}, schema{}, featured_media
	 */
	public static function create_page( WP_REST_Request $request ) {
		$p     = $request->get_json_params();
		$type  = sanitize_key( isset( $p['type'] ) ? $p['type'] : 'page' );
		$title = isset( $p['title'] ) ? sanitize_text_field( $p['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'ssc_title', 'Titel is verplicht.', array( 'status' => 400 ) );
		}
		if ( ! self::allowed_type( $type ) ) {
			return new WP_Error( 'ssc_type', 'Onbekend berichttype: ' . $type, array( 'status' => 400 ) );
		}
		$postarr = array(
			'post_type'    => $type,
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_name'    => isset( $p['slug'] ) ? sanitize_title( $p['slug'] ) : '',
			'post_parent'  => isset( $p['parent'] ) ? (int) $p['parent'] : 0,
			'menu_order'   => isset( $p['menu_order'] ) ? (int) $p['menu_order'] : 0,
			'post_content' => isset( $p['content'] ) ? (string) $p['content'] : '',
			'post_excerpt' => isset( $p['excerpt'] ) ? sanitize_textarea_field( $p['excerpt'] ) : '',
			'post_author'  => get_current_user_id(),
		);
		if ( ! empty( $p['template'] ) ) {
			$templates = wp_get_theme()->get_page_templates( null, $type );
			if ( 'default' !== $p['template'] && ! isset( $templates[ $p['template'] ] ) ) {
				return new WP_Error( 'ssc_template', 'Paginasjabloon bestaat niet in dit thema: ' . $p['template'], array( 'status' => 400 ) );
			}
			$postarr['page_template'] = $p['template'];
		}
		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$errors = array();
		foreach ( (array) ( isset( $p['acf'] ) ? $p['acf'] : array() ) as $selector => $value ) {
			$r = SSC_ACF::set( $selector, $value, $post_id );
			if ( is_wp_error( $r ) ) {
				$errors[] = $r->get_error_message();
			}
		}
		foreach ( (array) ( isset( $p['seo'] ) ? $p['seo'] : array() ) as $field => $value ) {
			$r = SSC_SEO::set( $post_id, $field, $value );
			if ( is_wp_error( $r ) ) {
				$errors[] = $r->get_error_message();
			}
		}
		if ( ! empty( $p['schema'] ) ) {
			$r = SSC_Schema::set( $post_id, $p['schema'] );
			if ( is_wp_error( $r ) ) {
				$errors[] = $r->get_error_message();
			}
		}
		if ( ! empty( $p['featured_media'] ) && 'attachment' === get_post_type( (int) $p['featured_media'] ) ) {
			set_post_thumbnail( $post_id, (int) $p['featured_media'] );
		}

		$post             = get_post( $post_id );
		$data             = self::summary( $post );
		$data['preview_link'] = get_preview_post_link( $post );
		$data['edit_link']    = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		$data['warnings']     = $errors;
		return $data;
	}

	/** Alleen pagina's die via deze koppeling zijn aangemaakt (auteur = API-gebruiker). */
	private static function own_draft( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! self::allowed_type( $post->post_type ) ) {
			return new WP_Error( 'ssc_post', 'Pagina niet gevonden.', array( 'status' => 404 ) );
		}
		if ( (int) $post->post_author !== (int) SSC_Setup::user_id() ) {
			return new WP_Error( 'ssc_not_own', 'Alleen pagina\'s die via deze koppeling zijn aangemaakt.', array( 'status' => 403 ) );
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			return new WP_Error( 'ssc_status', 'Alleen concepten (status nu: ' . $post->post_status . ').', array( 'status' => 409 ) );
		}
		return $post;
	}

	/** Concept publiceren. Body: { date? (Y-m-d H:i:s, sitetijd, voor inplannen) } */
	public static function publish_page( WP_REST_Request $request ) {
		$post = self::own_draft( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( self::require_review() ) {
			return new WP_Error( 'ssc_review', 'Beoordeling staat aan op deze site: publiceren gebeurt handmatig in WordPress.', array( 'status' => 409 ) );
		}
		$p    = $request->get_json_params();
		$args = array( 'ID' => $post->ID, 'post_status' => 'publish' );
		if ( ! empty( $p['date'] ) ) {
			$ts = strtotime( (string) $p['date'] );
			if ( false === $ts ) {
				return new WP_Error( 'ssc_date', 'Ongeldige datum.', array( 'status' => 400 ) );
			}
			$args['post_date']     = gmdate( 'Y-m-d H:i:s', $ts );
			$args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
			$args['edit_date']     = true;
			if ( $ts > current_time( 'timestamp' ) ) {
				$args['post_status'] = 'future';
			}
		}
		$r = wp_update_post( $args, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$post = get_post( $post->ID );
		return self::summary( $post );
	}

	/** Eigen concept naar de prullenbak (bijv. mislukte of test-pagina). */
	public static function trash_page( WP_REST_Request $request ) {
		$post = self::own_draft( (int) $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$r = wp_trash_post( $post->ID );
		return $r ? array( 'id' => $post->ID, 'status' => 'trash' ) : new WP_Error( 'ssc_trash', 'Naar prullenbak verplaatsen mislukt.', array( 'status' => 500 ) );
	}

	/* ---------- wijzigingen ---------- */

	/**
	 * Body: { changes: [{type, field, value, note}], apply: bool }
	 * Bij gepubliceerde pagina's en "beoordeling verplicht" blijft het een voorstel.
	 */
	public static function add_changes( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$p       = $request->get_json_params();
		$changes = isset( $p['changes'] ) && is_array( $p['changes'] ) ? $p['changes'] : array();
		if ( empty( $changes ) ) {
			return new WP_Error( 'ssc_changes', 'Geen wijzigingen meegegeven.', array( 'status' => 400 ) );
		}
		if ( count( $changes ) > 50 ) {
			return new WP_Error( 'ssc_changes', 'Maximaal 50 wijzigingen per aanvraag.', array( 'status' => 400 ) );
		}
		$apply   = ! empty( $p['apply'] );
		$is_live = in_array( get_post_status( $post_id ), array( 'publish', 'private', 'future' ), true );
		if ( $apply && $is_live && self::require_review() ) {
			return new WP_Error( 'ssc_review', 'Beoordeling is verplicht op deze site: wijzigingen aan live pagina\'s worden alleen als voorstel opgeslagen. Stuur zonder apply.', array( 'status' => 409 ) );
		}

		$out = array();
		foreach ( $changes as $c ) {
			$change = SSC_Changes::propose(
				$post_id,
				isset( $c['type'] ) ? sanitize_key( $c['type'] ) : '',
				isset( $c['field'] ) ? (string) $c['field'] : '',
				isset( $c['value'] ) ? $c['value'] : null,
				isset( $c['note'] ) ? sanitize_text_field( $c['note'] ) : ''
			);
			if ( is_wp_error( $change ) ) {
				$out[] = array( 'error' => $change->get_error_message(), 'input' => array( 'type' => isset( $c['type'] ) ? $c['type'] : null, 'field' => isset( $c['field'] ) ? $c['field'] : null ) );
				continue;
			}
			if ( $apply ) {
				$applied = SSC_Changes::apply( $change['id'] );
				$out[]   = is_wp_error( $applied ) ? array( 'error' => $applied->get_error_message(), 'change' => $change ) : $applied;
			} else {
				$out[] = $change;
			}
		}
		return array( 'post_id' => $post_id, 'results' => $out );
	}

	public static function list_changes( WP_REST_Request $request ) {
		return SSC_Changes::query(
			array(
				'status'  => $request->get_param( 'status' ) ? sanitize_key( $request->get_param( 'status' ) ) : '',
				'post_id' => (int) $request->get_param( 'post_id' ),
				'limit'   => (int) $request->get_param( 'limit' ),
			)
		);
	}

	public static function apply_change( WP_REST_Request $request ) {
		$change = SSC_Changes::get( (int) $request['id'] );
		if ( ! $change ) {
			return new WP_Error( 'ssc_change', 'Wijziging niet gevonden.', array( 'status' => 404 ) );
		}
		$is_live = in_array( get_post_status( $change['post_id'] ), array( 'publish', 'private', 'future' ), true );
		if ( $is_live && self::require_review() ) {
			return new WP_Error( 'ssc_review', 'Beoordeling is verplicht: live wijzigingen worden toegepast via Extra → Steenstra SEO in WordPress.', array( 'status' => 409 ) );
		}
		return SSC_Changes::apply( (int) $request['id'] );
	}

	public static function revert_change( WP_REST_Request $request ) {
		return SSC_Changes::revert( (int) $request['id'] );
	}

	public static function reject_change( WP_REST_Request $request ) {
		return SSC_Changes::reject( (int) $request['id'] );
	}

	/* ---------- media ---------- */

	/**
	 * Body: { url | data(base64), filename, alt, title, caption }
	 * Alleen afbeeldingen (jpg, png, webp, gif), max. 10 MB.
	 */
	public static function upload_media( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$p        = $request->get_json_params();
		$filename = sanitize_file_name( isset( $p['filename'] ) ? $p['filename'] : '' );
		$allowed  = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );

		if ( ! empty( $p['url'] ) ) {
			$url = esc_url_raw( $p['url'] );
			if ( 0 !== strpos( $url, 'https://' ) ) {
				return new WP_Error( 'ssc_media', 'Alleen https-URL\'s.', array( 'status' => 400 ) );
			}
			$tmp = download_url( $url, 30 );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			if ( '' === $filename ) {
				$filename = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			}
		} elseif ( ! empty( $p['data'] ) ) {
			$bytes = base64_decode( (string) $p['data'], true );
			if ( false === $bytes ) {
				return new WP_Error( 'ssc_media', 'Ongeldige base64-data.', array( 'status' => 400 ) );
			}
			$tmp = wp_tempnam( $filename ? $filename : 'ssc-upload' );
			file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			return new WP_Error( 'ssc_media', 'Geef url of data mee.', array( 'status' => 400 ) );
		}

		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( filesize( $tmp ) > 10 * MB_IN_BYTES ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'ssc_media', 'Bestand is groter dan 10 MB.', array( 'status' => 400 ) );
		}
		$check = wp_check_filetype_and_ext( $tmp, $filename );
		if ( ! in_array( $ext, $allowed, true ) || empty( $check['type'] ) || 0 !== strpos( (string) $check['type'], 'image/' ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'ssc_media', 'Alleen afbeeldingen (jpg, png, webp, gif).', array( 'status' => 400 ) );
		}

		$id = media_handle_sideload(
			array( 'name' => $filename, 'tmp_name' => $tmp ),
			0,
			isset( $p['title'] ) ? sanitize_text_field( $p['title'] ) : null
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return $id;
		}
		if ( ! empty( $p['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $p['alt'] ) );
		}
		if ( ! empty( $p['caption'] ) ) {
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => sanitize_text_field( $p['caption'] ) ) );
		}
		return array(
			'id'  => (int) $id,
			'url' => wp_get_attachment_url( $id ),
			'alt' => get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}
}
