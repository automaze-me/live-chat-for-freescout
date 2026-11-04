<?php
/**
 * Removes plugin data on uninstall.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'lcff_settings' );
