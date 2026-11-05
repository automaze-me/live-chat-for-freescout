<?php
/**
 * Removes plugin data on uninstall: the settings and the rate-limit
 * transients, on every site of a multisite network.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$lcff_cleanup = function () {
	global $wpdb;
	delete_option( 'lcff_settings' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients with a dynamic name; no API lists them.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_lcff_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_lcff_rl_' ) . '%'
		)
	);
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $lcff_site_id ) {
		switch_to_blog( $lcff_site_id );
		$lcff_cleanup();
		restore_current_blog();
	}
} else {
	$lcff_cleanup();
}
