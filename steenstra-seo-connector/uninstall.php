<?php
/**
 * Opruimen bij verwijderen: API-gebruiker (inhoud gaat naar de eerste beheerder), rol, opties en log.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$ssc_user = get_user_by( 'login', 'steenstra-seo' );
if ( $ssc_user ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$ssc_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_delete_user( $ssc_user->ID, $ssc_admins ? (int) $ssc_admins[0] : null );
}
remove_role( 'steenstra_seo' );
delete_option( 'ssc_user_id' );
delete_option( 'ssc_db_version' );
delete_option( 'ssc_require_review' );
delete_option( 'ssc_version' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ssc_changes' ); // phpcs:ignore WordPress.DB
