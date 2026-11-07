<?php
/**
 * The inline LCFF_CONFIG script: admin texts are decoded before they go into
 * it, so the JSON must not carry a raw "<", ">" or "&". A team name such as
 * "<!--<script x" would otherwise put the HTML parser into the "script data
 * escaped" state, and the page's next </script> would no longer end the
 * inline script.
 * Run: php tests/widget-config.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__ );

// Tiny stand-ins for the WordPress functions the main file and the widget's enqueue() call.
$GLOBALS['lcff_inline']   = array();
$GLOBALS['lcff_settings'] = array();
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {}
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://shop.example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function __( $text, $domain = null ) { return $text; }
function is_admin() { return false; }
function is_multisite() { return ! empty( $GLOBALS['lcff_multisite'] ); }
function is_main_site( $site_id = null, $network_id = null ) { return ! empty( $GLOBALS['lcff_main_site'] ); }
function get_current_blog_id() { return 7; }
function get_option( $name, $default = false ) { return $GLOBALS['lcff_settings']; }
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url() { return 'https://shop.example.test'; }
function admin_url( $path = '' ) { return 'https://shop.example.test/wp-admin/' . $path; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_add_inline_script( $handle, $js, $position = 'after' ) { $GLOBALS['lcff_inline'][] = $js; }
// As in WordPress: the flags reach json_encode().
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function wp_get_attachment_image_url() { return ''; }
function get_privacy_policy_url() { return ''; }
function wp_timezone_string() { return 'Europe/Berlin'; }
function determine_locale() { return 'en_US'; }

$ok = true;
function t( $name, $got, $expected ) {
	global $ok;
	if ( $got === $expected ) {
		echo "ok   $name\n";
	} else {
		$ok = false;
		echo "FAIL $name: got " . var_export( $got, true ) . ', expected ' . var_export( $expected, true ) . "\n";
	}
}

// Any PHP warning fails the run.
set_error_handler( function ( $no, $msg, $file, $line ) {
	global $ok;
	$ok = false;
	echo "FAIL PHP warning: $msg ($file:$line)\n";
	return true;
} );

require $root . '/live-chat-for-freescout.php';

/** The inline script enqueue() adds for these stored settings. */
function lcff_inline_for( array $settings ) {
	$GLOBALS['lcff_settings'] = array_merge(
		array(
			'freescout_url' => 'https://help.example.test',
			'site_id'       => str_repeat( 'ab', 16 ),
		),
		$settings
	);
	$GLOBALS['lcff_inline'] = array();
	$_SERVER['REQUEST_URI'] = '/';
	LCFF_Widget::enqueue();
	return isset( $GLOBALS['lcff_inline'][0] ) ? $GLOBALS['lcff_inline'][0] : '';
}

$js = lcff_inline_for(
	array(
		// Stored entity-encoded (sanitize_text_field() keeps entities); decoded for the widget.
		'team_name'    => '&lt;!--&lt;script x',
		'welcome'      => 'Hi </script><script>alert(1)</script> & welcome',
		'welcome_away' => "Back soon -->",
	)
);

t( 'inline script found', 1 === preg_match( '/^window\.LCFF_CONFIG = (.*);$/s', $js, $m ), true );
$json = isset( $m[1] ) ? $m[1] : '';
t( 'no raw < in the config', strpos( $json, '<' ), false );
t( 'no raw > in the config', strpos( $json, '>' ), false );
t( 'no raw & in the config', strpos( $json, '&' ), false );

$cfg = json_decode( $json, true );
t( 'teamName decodes to the admin text', isset( $cfg['teamName'] ) ? $cfg['teamName'] : null, '<!--<script x' );
t( 'welcome decodes to the admin text', isset( $cfg['welcome'] ) ? $cfg['welcome'] : null, 'Hi </script><script>alert(1)</script> & welcome' );
t( 'welcomeAway decodes to the admin text', isset( $cfg['welcomeAway'] ) ? $cfg['welcomeAway'] : null, 'Back soon -->' );
t( 'apiBase unchanged', isset( $cfg['apiBase'] ) ? $cfg['apiBase'] : null, 'https://help.example.test/wordpress-chat/api/v1/sites/' . str_repeat( 'ab', 16 ) );

// Storage suffix: plain on a single site, the site ID on a network's sub-site.
t( 'storageSuffix on a single site', isset( $cfg['storageSuffix'] ) ? $cfg['storageSuffix'] : null, '' );

function lcff_suffix_for( $multisite, $main ) {
	$GLOBALS['lcff_multisite'] = $multisite;
	$GLOBALS['lcff_main_site'] = $main;
	$c = json_decode( preg_replace( '/^window\.LCFF_CONFIG = (.*);$/s', '$1', lcff_inline_for( array() ) ), true );
	return isset( $c['storageSuffix'] ) ? $c['storageSuffix'] : null;
}
t( 'storageSuffix on a network sub-site (ID 7)', lcff_suffix_for( true, false ), '_7' );
t( 'storageSuffix on the main site of a network', lcff_suffix_for( true, true ), '' );

/** LCFF_CONFIG as an array, for these stored settings. */
function lcff_config_for( array $settings ) {
	$js = lcff_inline_for( $settings );
	return 1 === preg_match( '/^window\.LCFF_CONFIG = (.*);$/s', $js, $m ) ? json_decode( $m[1], true ) : array();
}

// The chat button: its own color (empty means the accent), the white ring, the icon color.
$c = lcff_config_for( array() );
t( 'button: no settings, the default accent', array( $c['buttonColor'], $c['buttonRing'], $c['buttonIcon'] ), array( '#2f6f5e', false, '#ffffff' ) );
$c = lcff_config_for( array( 'accent' => '#131a45', 'button_color' => '' ) );
t( 'button: empty color, the accent', array( $c['buttonColor'], $c['accent'] ), array( '#131a45', '#131a45' ) );
$c = lcff_config_for( array( 'accent' => '#131a45', 'button_color' => '#ba3b46', 'button_ring' => true ) );
t( 'button: own color and ring, accent unchanged', array( $c['buttonColor'], $c['buttonRing'], $c['buttonIcon'], $c['accent'] ), array( '#ba3b46', true, '#ffffff', '#131a45' ) );
$c = lcff_config_for( array( 'button_color' => '#f0c000' ) );
t( 'button: a light color gets the dark icon', $c['buttonIcon'], '#1d2327' );
$c = lcff_config_for( array( 'accent' => '#f0c000', 'button_color' => '' ) );
t( 'button: a light accent gets the dark icon', array( $c['buttonColor'], $c['buttonIcon'] ), array( '#f0c000', '#1d2327' ) );
$c = lcff_config_for( array( 'accent' => '#131a45', 'button_color' => 'red', 'button_ring' => 'yes' ) );
t( 'button: a stored junk color falls back to the accent, junk ring is off', array( $c['buttonColor'], $c['buttonRing'] ), array( '#131a45', false ) );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
