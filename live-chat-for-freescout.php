<?php
/**
 * Plugin Name:       Live Chat for FreeScout
 * Description:       Adds a live chat bubble to your site that delivers visitor messages to your FreeScout help desk.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            automaze.me
 * Author URI:        https://automaze.me
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       live-chat-for-freescout
 * Domain Path:       /languages
 * Update URI:        https://github.com/automaze-me/live-chat-for-freescout
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
require_once LCFF_PLUGIN_DIR . 'includes/class-lcff-privacy.php';
require_once LCFF_PLUGIN_DIR . 'includes/class-lcff-updater.php';

/**
 * Loads the bundled translations (languages/). On init: WordPress 6.7+
 * warns when a plugin loads its translations earlier.
 */
function lcff_load_textdomain() {
	// Bundled German files stay until translate.wordpress.org ships language packs; WordPress's just-in-time loader reads only wp-content/languages/plugins, so without this call they never load.
	load_plugin_textdomain( 'live-chat-for-freescout', false, dirname( plugin_basename( LCFF_PLUGIN_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- see the comment above.
}
add_action( 'init', 'lcff_load_textdomain' );

LCFF_Settings::init();
LCFF_Widget::init();
LCFF_Identity::init();
LCFF_Connection::init();
LCFF_Privacy::init();
LCFF_Updater::init();
