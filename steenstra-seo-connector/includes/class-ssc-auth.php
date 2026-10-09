<?php
defined( 'ABSPATH' ) || exit;

/**
 * Controle van ondertekende aanvragen (Ed25519).
 *
 * Ondertekend bericht (regels gescheiden door "\n"):
 *   SSC1
 *   <METHOD>
 *   <host, kleine letters, zonder poort>
 *   <pad + querystring zoals verstuurd>
 *   <unix-tijdstempel>
 *   <nonce>
 *   <sha256-hex van de body>
 *
 * Headers: X-Steenstra-Timestamp, X-Steenstra-Nonce, X-Steenstra-Signature (base64).
 */
class SSC_Auth {

	/** @return true|WP_Error */
	public static function permission( WP_REST_Request $request ) {
		$ts    = (string) $request->get_header( 'x_steenstra_timestamp' );
		$nonce = (string) $request->get_header( 'x_steenstra_nonce' );
		$sig   = (string) $request->get_header( 'x_steenstra_signature' );

		if ( '' === $ts || '' === $nonce || '' === $sig ) {
			return self::deny( 'ssc_unsigned', 'Aanvraag is niet ondertekend.' );
		}
		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > SSC_MAX_CLOCK_SKEW ) {
			return self::deny( 'ssc_clock', 'Tijdstempel ongeldig of verlopen.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $nonce ) ) {
			return self::deny( 'ssc_nonce', 'Nonce ongeldig.' );
		}

		$signature = base64_decode( $sig, true );
		$key       = base64_decode( SSC_PUBLIC_KEY, true );
		if ( false === $signature || false === $key || 64 !== strlen( $signature ) || 32 !== strlen( $key ) ) {
			return self::deny( 'ssc_signature', 'Handtekening ongeldig.' );
		}

		$host = strtolower( (string) ( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '' ) );
		$host = preg_replace( '/:\d+$/', '', $host );
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		$message = implode(
			"\n",
			array(
				'SSC1',
				strtoupper( $request->get_method() ),
				$host,
				$path,
				$ts,
				$nonce,
				hash( 'sha256', (string) $request->get_body() ),
			)
		);

		try {
			$valid = sodium_crypto_sign_verify_detached( $signature, $message, $key );
		} catch ( \Throwable $e ) {
			$valid = false;
		}
		if ( ! $valid ) {
			return self::deny( 'ssc_signature', 'Handtekening klopt niet.' );
		}

		// Elke nonce maar één keer.
		$nonce_key = 'ssc_n_' . md5( $nonce );
		if ( get_transient( $nonce_key ) ) {
			return self::deny( 'ssc_replay', 'Aanvraag is al eerder gebruikt.' );
		}
		set_transient( $nonce_key, 1, 2 * SSC_MAX_CLOCK_SKEW + 60 );

		$user_id = SSC_Setup::user_id();
		if ( ! $user_id ) {
			SSC_Setup::create_role();
			SSC_Setup::create_user();
			$user_id = SSC_Setup::user_id();
		}
		if ( ! $user_id ) {
			return self::deny( 'ssc_user', 'API-gebruiker ontbreekt.', 500 );
		}
		wp_set_current_user( $user_id );
		return true;
	}

	private static function deny( $code, $message, $status = 401 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
