<?php
defined( 'ABSPATH' ) || exit;

/**
 * Beheerpagina onder Extra → Steenstra SEO: status, voorstellen toepassen/afwijzen, terugdraaien.
 * Alleen zichtbaar voor beheerders. Laadt geen eigen CSS of JavaScript.
 */
class SSC_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'steenstra-seo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ssc_action', array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_management_page( 'Steenstra SEO', 'Steenstra SEO', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function action_button( $action, $id, $label, $primary = false ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=ssc_action&do=' . $action . '&id=' . (int) $id ), 'ssc_' . $action . '_' . (int) $id );
		return '<a class="button' . ( $primary ? ' button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Geen toegang.' );
		}
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'ssc_' . $do . '_' . $id );

		$msg = '';
		try {
			switch ( $do ) {
				case 'apply':
					$r = SSC_Changes::apply( $id );
					break;
				case 'reject':
					$r = SSC_Changes::reject( $id );
					break;
				case 'revert':
					$r = SSC_Changes::revert( $id );
					break;
				case 'review_on':
					update_option( SSC_API::OPTION_REQUIRE_REVIEW, '1', false );
					$r = true;
					break;
				case 'review_off':
					update_option( SSC_API::OPTION_REQUIRE_REVIEW, '0', false );
					$r = true;
					break;
				default:
					$r = new WP_Error( 'ssc', 'Onbekende actie.' );
			}
			$msg = is_wp_error( $r ) ? $r->get_error_message() : 'ok';
		} catch ( \Throwable $e ) {
			$msg = 'Fout: ' . $e->getMessage();
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'ssc_msg' => rawurlencode( $msg ) ), admin_url( 'tools.php' ) ) );
		exit;
	}

	private static function short( $value ) {
		$text = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		$text = wp_strip_all_tags( $text );
		return mb_strlen( $text ) > 220 ? mb_substr( $text, 0, 220 ) . '…' : $text;
	}

	private static function table( $rows, $actions ) {
		if ( empty( $rows ) ) {
			echo '<p>Geen.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Pagina</th><th>Wat</th><th>Oud</th><th>Nieuw</th><th>Notitie</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $c ) {
			echo '<tr>';
			echo '<td>' . (int) $c['id'] . '</td>';
			echo '<td><a href="' . esc_url( get_permalink( $c['post_id'] ) ) . '" target="_blank">' . esc_html( $c['post_title'] ) . '</a></td>';
			echo '<td>' . esc_html( $c['type'] . ( $c['field'] ? ' · ' . $c['field'] : '' ) ) . '</td>';
			echo '<td>' . esc_html( self::short( $c['old_value'] ) ) . '</td>';
			echo '<td>' . esc_html( self::short( $c['new_value'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $c['note'] ) . '</td>';
			echo '<td style="white-space:nowrap">';
			foreach ( $actions as $action => $label ) {
				echo self::action_button( $action, $c['id'], $label, 'apply' === $action ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$status = SSC_API::status();
		echo '<div class="wrap"><h1>Steenstra SEO</h1>';

		if ( isset( $_GET['ssc_msg'] ) ) {
			$msg = sanitize_text_field( rawurldecode( wp_unslash( $_GET['ssc_msg'] ) ) );
			$ok  = 'ok' === $msg;
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $ok ? 'Gelukt.' : $msg ) . '</p></div>';
		}

		echo '<p>Deze plugin geeft Steenstra Media beveiligde API-toegang voor SEO-werk: teksten, SEO-titels en -omschrijvingen, schema en afbeeldingen. Thema, menu\'s, plugins en instellingen worden niet aangepast. Wijzigingen worden gelogd en zijn hieronder terug te draaien. Alleen digitaal ondertekende aanvragen van Steenstra Media worden geaccepteerd.</p>';
		echo '<table class="form-table"><tbody>';
		$m = SSC_Updater::manifest();
		echo '<tr><th>Versie</th><td>' . esc_html( SSC_VERSION ) . ( $m && version_compare( $m['version'], SSC_VERSION, '>' ) ? ' (update ' . esc_html( $m['version'] ) . ' beschikbaar, wordt automatisch geïnstalleerd)' : ' (automatische updates aan)' ) . '</td></tr>';
		echo '<tr><th>SEO-plugin</th><td>' . esc_html( $status['seo_plugin'] ) . '</td></tr>';
		echo '<tr><th>ACF</th><td>' . esc_html( $status['acf'] ? $status['acf'] : 'niet actief' ) . '</td></tr>';
		echo '<tr><th>Beoordeling verplicht</th><td>' . ( $status['require_review'] ? 'Ja: wijzigingen aan live pagina\'s komen hieronder als voorstel binnen. ' . self::action_button( 'review_off', 0, 'Uitzetten' ) : 'Nee: wijzigingen mogen direct live. ' . self::action_button( 'review_on', 0, 'Aanzetten' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tbody></table>';

		echo '<h2>Voorstellen</h2>';
		self::table( SSC_Changes::query( array( 'status' => 'proposed', 'limit' => 200 ) ), array( 'apply' => 'Toepassen', 'reject' => 'Afwijzen' ) );

		echo '<h2>Toegepast (laatste 50)</h2>';
		self::table( SSC_Changes::query( array( 'status' => 'applied', 'limit' => 50 ) ), array( 'revert' => 'Terugdraaien' ) );
		echo '</div>';
	}
}
