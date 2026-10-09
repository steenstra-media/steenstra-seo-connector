<?php
defined( 'ABSPATH' ) || exit;

/**
 * Installatie: rol, API-gebruiker zonder inlogmogelijkheid en de wijzigingslog-tabel.
 */
class SSC_Setup {

	const DB_VERSION        = '1';
	const OPTION_DB_VERSION = 'ssc_db_version';
	const OPTION_USER_ID    = 'ssc_user_id';

	/** Rechten van de API-rol: alleen pagina's, berichten en media bewerken. */
	public static function role_caps() {
		return array(
			'read'                   => true,
			'edit_pages'             => true,
			'edit_others_pages'      => true,
			'edit_published_pages'   => true,
			'publish_pages'          => true,
			'edit_private_pages'     => true,
			'read_private_pages'     => true,
			'edit_posts'             => true,
			'edit_others_posts'      => true,
			'edit_published_posts'   => true,
			'publish_posts'          => true,
			'edit_private_posts'     => true,
			'read_private_posts'     => true,
			'upload_files'           => true,
		);
	}

	public static function activate() {
		self::create_role();
		self::create_user();
		self::create_table();
	}

	public static function deactivate() {
		// Gebruiker en log blijven bestaan; zonder actieve plugin is er geen toegang.
	}

	public static function maybe_upgrade() {
		if ( get_option( 'ssc_version' ) !== SSC_VERSION ) {
			// Eenmalig na een update: oude, eventueel gecachte API-antwoorden uit de cache halen.
			// Op "init" zodat cacheplugins hun acties al geregistreerd hebben.
			add_action(
				'init',
				function () {
					update_option( 'ssc_version', SSC_VERSION, false );
					do_action( 'litespeed_purge_all' );
				},
				99
			);
		}
		if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
			self::create_table();
		}
		if ( ! get_role( SSC_ROLE ) ) {
			self::create_role();
		}
		if ( ! self::user_id() ) {
			self::create_user();
		}
	}

	public static function create_role() {
		remove_role( SSC_ROLE );
		add_role( SSC_ROLE, 'Steenstra SEO (API)', self::role_caps() );
	}

	public static function create_user() {
		$user = get_user_by( 'login', SSC_USER_LOGIN );
		if ( ! $user ) {
			$host  = wp_parse_url( home_url(), PHP_URL_HOST );
			$email = 'seo-connector+' . preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) $host ) ) . '@steenstramedia.nl';
			if ( email_exists( $email ) ) {
				$email = 'seo-connector+' . wp_generate_password( 8, false ) . '@steenstramedia.nl';
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => SSC_USER_LOGIN,
					'user_pass'    => wp_generate_password( 64, true, true ),
					'user_email'   => $email,
					'display_name' => 'Steenstra SEO',
					'role'         => SSC_ROLE,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return;
			}
		} else {
			$user_id = $user->ID;
			$user->set_role( SSC_ROLE );
		}
		update_option( self::OPTION_USER_ID, (int) $user_id, false );
	}

	public static function user_id() {
		$id = (int) get_option( self::OPTION_USER_ID );
		if ( $id && get_userdata( $id ) ) {
			return $id;
		}
		$user = get_user_by( 'login', SSC_USER_LOGIN );
		return $user ? (int) $user->ID : 0;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ssc_changes';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				type varchar(32) NOT NULL,
				field varchar(191) NOT NULL DEFAULT '',
				old_value longtext NULL,
				new_value longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'proposed',
				note text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY post_id (post_id),
				KEY status (status)
			) {$charset};"
		);
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/** De API-gebruiker kan nooit via het inlogscherm, XML-RPC of applicatiewachtwoorden inloggen. */
	public static function register_login_guards() {
		add_filter(
			'authenticate',
			function ( $user, $username ) {
				if ( is_string( $username ) && strtolower( $username ) === SSC_USER_LOGIN ) {
					return new WP_Error( 'ssc_no_login', 'Dit account is alleen voor de API-koppeling.' );
				}
				if ( $user instanceof WP_User && $user->user_login === SSC_USER_LOGIN ) {
					return new WP_Error( 'ssc_no_login', 'Dit account is alleen voor de API-koppeling.' );
				}
				return $user;
			},
			999,
			2
		);
		add_filter(
			'wp_is_application_passwords_available_for_user',
			function ( $available, $user ) {
				return ( $user instanceof WP_User && $user->user_login === SSC_USER_LOGIN ) ? false : $available;
			},
			10,
			2
		);
		add_filter(
			'allow_password_reset',
			function ( $allow, $user_id ) {
				return ( (int) $user_id === SSC_Setup::user_id() ) ? false : $allow;
			},
			10,
			2
		);
	}
}
