<?php
/**
 * Plugin Name:       Live Chat for FreeScout
 * Description:       Adds a live chat bubble to your site that delivers visitor messages to your FreeScout help desk.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       live-chat-for-freescout
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCFF_VERSION', '0.1.0' );
define( 'LCFF_PLUGIN_FILE', __FILE__ );
define( 'LCFF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LCFF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once LCFF_PLUGIN_DIR . 'includes/class-lcff-crypto.php';
require_once LCFF_PLUGIN_DIR . 'includes/class-lcff-appearance.php';
require_once LCFF_PLUGIN_DIR . 'includes/class-lcff-settings.php';
require_once LCFF_PLUGIN_DIR . 'includes/class-lcff-identity.php';
require_once LCFF_PLUGIN_DIR . 'includes/class-lcff-connection.php';
require_once LCFF_PLUGIN_DIR . 'includes/class-lcff-widget.php';

LCFF_Settings::init();
LCFF_Widget::init();
LCFF_Identity::init();
LCFF_Connection::init();
