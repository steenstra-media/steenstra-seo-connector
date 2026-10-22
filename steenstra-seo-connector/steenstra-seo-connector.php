<?php
/**
 * Plugin Name:       Steenstra SEO Connector
 * Description:       Beveiligde API-koppeling voor SEO-beheer door Steenstra Media: pagina's, ACF-bouwblokken, SEO-titels en -omschrijvingen, schema en media. Alleen ondertekende aanvragen worden geaccepteerd.
 * Version:           0.3.0
 * Author:            Steenstra Media
 * Author URI:        https://www.steenstramedia.nl/
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       steenstra-seo-connector
 */

defined( 'ABSPATH' ) || exit;

// Veiligheid: dubbel geladen of te oude PHP → niets doen in plaats van de site te breken.
if ( defined( 'SSC_VERSION' ) || class_exists( 'SSC_Setup' ) ) {
	return;
}
if ( version_compare( PHP_VERSION, '7.4', '<' ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-warning"><p>Steenstra SEO Connector is niet actief: PHP 7.4 of hoger met sodium is nodig.</p></div>';
		}
	);
	return;
}

define( 'SSC_VERSION', '0.3.0' );
define( 'SSC_FILE', __FILE__ );
define( 'SSC_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Publieke Ed25519-sleutel (base64). De bijbehorende privésleutel staat alleen
 * bij Steenstra Media. Een nieuwe sleutel = een nieuwe plugin-versie.
 */
define( 'SSC_PUBLIC_KEY', '+TRniQ2/v/HltwsJmP02cYxrJCXiXdkuNafUQLaom5w=' );

define( 'SSC_USER_LOGIN', 'steenstra-seo' );
define( 'SSC_ROLE', 'steenstra_seo' );
define( 'SSC_NAMESPACE', 'steenstra-seo/v1' );
define( 'SSC_MAX_CLOCK_SKEW', 300 ); // seconden

require_once SSC_DIR . 'includes/class-ssc-setup.php';
require_once SSC_DIR . 'includes/class-ssc-auth.php';
require_once SSC_DIR . 'includes/class-ssc-seo.php';
require_once SSC_DIR . 'includes/class-ssc-acf.php';
require_once SSC_DIR . 'includes/class-ssc-elementor.php';
require_once SSC_DIR . 'includes/class-ssc-changes.php';
require_once SSC_DIR . 'includes/class-ssc-schema.php';
require_once SSC_DIR . 'includes/class-ssc-cache.php';
require_once SSC_DIR . 'includes/class-ssc-preview.php';
require_once SSC_DIR . 'includes/class-ssc-api.php';
require_once SSC_DIR . 'includes/class-ssc-admin.php';
require_once SSC_DIR . 'includes/class-ssc-updater.php';

register_activation_hook( __FILE__, array( 'SSC_Setup', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SSC_Setup', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SSC_Setup', 'maybe_upgrade' ) );

/*
 * API-antwoorden mogen nooit gecachet worden (LiteSpeed, Varnish, Cloudflare, WP-cacheplugins):
 * anders zou een cache ze zonder handtekening aan anderen kunnen tonen.
 */
function ssc_is_api_request() {
	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	return false !== strpos( $uri, '/steenstra-seo/v1' ) || false !== strpos( rawurldecode( $uri ), '/steenstra-seo/v1' ) || 0 === strpos( $route, '/steenstra-seo/v1' );
}
function ssc_no_cache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'Steenstra SEO API' );
	if ( ! headers_sent() ) {
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'CDN-Cache-Control: no-store' );
	}
}
if ( ssc_is_api_request() ) {
	ssc_no_cache();
	add_action( 'init', 'ssc_no_cache', 0 );
	add_action( 'rest_api_init', 'ssc_no_cache', 0 );
}
add_filter(
	'rest_post_dispatch',
	function ( $response, $server, $request ) {
		if ( $response instanceof WP_REST_Response && 0 === strpos( (string) $request->get_route(), '/' . SSC_NAMESPACE ) ) {
			ssc_no_cache();
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
			$response->header( 'CDN-Cache-Control', 'no-store' );
		}
		return $response;
	},
	10,
	3
);
add_action( 'rest_api_init', array( 'SSC_API', 'register_routes' ) );
SSC_Setup::register_login_guards();
SSC_Schema::register_output();
SSC_Updater::init();
SSC_Preview::init();

if ( is_admin() ) {
	SSC_Admin::init();
}
