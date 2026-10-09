<?php
defined( 'ABSPATH' ) || exit;

/**
 * Automatische updates vanuit GitHub, beveiligd met dezelfde Ed25519-sleutel.
 *
 * - update.json + update.json.sig staan in de repository. De plugin accepteert de
 *   manifest alleen als de handtekening klopt.
 * - De zip wordt vóór installatie gecontroleerd op de sha256 uit de ondertekende manifest.
 * - Alleen hogere versies; WordPress- en PHP-eisen worden gerespecteerd.
 */
class SSC_Updater {

	const BASE      = 'https://raw.githubusercontent.com/steenstra-media/steenstra-seo-connector/main/';
	const CACHE_KEY = 'ssc_update_manifest';
	const SLUG      = 'steenstra-seo-connector';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verified_download' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
	}

	private static function basename() {
		return plugin_basename( SSC_FILE );
	}

	/** @return array|null Geverifieerde manifest of null. */
	public static function manifest( $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$manifest = null;
		try {
			$args = array( 'timeout' => 10, 'headers' => array( 'Cache-Control' => 'no-cache' ) );
			$json = wp_remote_get( self::BASE . 'update.json?t=' . time(), $args );
			$sig  = wp_remote_get( self::BASE . 'update.json.sig?t=' . time(), $args );
			if ( ! is_wp_error( $json ) && ! is_wp_error( $sig ) && 200 === wp_remote_retrieve_response_code( $json ) && 200 === wp_remote_retrieve_response_code( $sig ) ) {
				$body      = wp_remote_retrieve_body( $json );
				$signature = base64_decode( trim( wp_remote_retrieve_body( $sig ) ), true );
				$key       = base64_decode( SSC_PUBLIC_KEY, true );
				if ( $signature && 64 === strlen( $signature ) && sodium_crypto_sign_verify_detached( $signature, $body, $key ) ) {
					$data = json_decode( $body, true );
					if ( is_array( $data ) && ! empty( $data['version'] ) && ! empty( $data['package'] ) && ! empty( $data['sha256'] )
						&& 0 === strpos( $data['package'], self::BASE ) ) {
						$manifest = $data;
					}
				}
			}
		} catch ( \Throwable $e ) {
			$manifest = null;
		}
		// Ook een mislukte check cachen (korter), zodat GitHub niet bij elke pagina wordt aangeroepen.
		set_site_transient( self::CACHE_KEY, $manifest ? $manifest : array(), $manifest ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $manifest;
	}

	private static function compatible( $m ) {
		global $wp_version;
		if ( ! empty( $m['requires'] ) && version_compare( $wp_version, $m['requires'], '<' ) ) {
			return false;
		}
		if ( ! empty( $m['requires_php'] ) && version_compare( PHP_VERSION, $m['requires_php'], '<' ) ) {
			return false;
		}
		return true;
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$m = self::manifest();
		if ( ! $m || ! self::compatible( $m ) ) {
			return $transient;
		}
		$item = (object) array(
			'id'           => self::BASE,
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'new_version'  => $m['version'],
			'url'          => 'https://www.steenstramedia.nl/',
			'package'      => $m['package'],
			'requires'     => isset( $m['requires'] ) ? $m['requires'] : '',
			'requires_php' => isset( $m['requires_php'] ) ? $m['requires_php'] : '',
			'tested'       => isset( $m['tested'] ) ? $m['tested'] : '',
		);
		if ( version_compare( $m['version'], SSC_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$m = self::manifest();
		if ( ! $m ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Steenstra SEO Connector',
			'slug'          => self::SLUG,
			'version'       => $m['version'],
			'author'        => 'Steenstra Media',
			'homepage'      => 'https://www.steenstramedia.nl/',
			'requires'      => isset( $m['requires'] ) ? $m['requires'] : '',
			'requires_php'  => isset( $m['requires_php'] ) ? $m['requires_php'] : '',
			'tested'        => isset( $m['tested'] ) ? $m['tested'] : '',
			'download_link' => $m['package'],
			'sections'      => array( 'changelog' => isset( $m['changelog'] ) ? wp_kses_post( $m['changelog'] ) : '' ),
		);
	}

	/** Download zelf uitvoeren en de sha256 controleren tegen de ondertekende manifest. */
	public static function verified_download( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, self::BASE ) ) {
			return $reply;
		}
		$m = self::manifest( true );
		if ( ! $m || $m['package'] !== $package ) {
			return new WP_Error( 'ssc_update', 'Steenstra SEO Connector: update-manifest niet geverifieerd, update afgebroken.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $package, 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! hash_equals( strtolower( $m['sha256'] ), hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'ssc_update', 'Steenstra SEO Connector: controlegetal van de update klopt niet, update afgebroken.' );
		}
		return $tmp;
	}

	/** Deze plugin updatet altijd automatisch (alleen geverifieerde versies, zie verified_download). */
	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && $item->plugin === self::basename() ) {
			return true;
		}
		return $update;
	}
}
