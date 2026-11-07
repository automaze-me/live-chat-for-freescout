<?php
/**
 * Updates from GitHub releases: the update_plugins_github.com filter answers
 * for this plugin only, from the latest release's update.json; the package
 * URL is built from the checked version, never taken from the manifest; a
 * failed or malformed answer means "no update"; one fetch serves the check
 * and the "View details" box (plugins_api) for an hour.
 * Run: php tests/updater.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LCFF_PLUGIN_FILE', '/srv/wp/wp-content/plugins/live-chat-for-freescout/live-chat-for-freescout.php' );
define( 'LCFF_VERSION', '0.1.0' );
define( 'HOUR_IN_SECONDS', 3600 );

const MANIFEST_URL = 'https://github.com/automaze-me/live-chat-for-freescout/releases/latest/download/update.json';
const BASENAME     = 'live-chat-for-freescout/live-chat-for-freescout.php';

// Tiny stand-ins for the WordPress functions the updater calls.
$GLOBALS['filters']   = array();
$GLOBALS['requests']  = array();
$GLOBALS['response']  = null;
$GLOBALS['site_tr']   = array();
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['filters'][] = array( $hook, $cb, $priority, $args ); }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function __( $text, $domain = null ) { return $text; }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html__( $t, $domain = null ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
class WP_Error {}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['requests'][] = array( $url, $args );
	return $GLOBALS['response'];
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function get_site_transient( $k ) { return array_key_exists( $k, $GLOBALS['site_tr'] ) ? $GLOBALS['site_tr'][ $k ][0] : false; }
function set_site_transient( $k, $v, $ttl ) { $GLOBALS['site_tr'][ $k ] = array( $v, $ttl ); return true; }

require __DIR__ . '/../includes/class-lcff-updater.php';

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

/** A fresh state: no cache, no requests, this answer from GitHub. */
function answer( $code, $body ) {
	$GLOBALS['site_tr']  = array();
	$GLOBALS['requests'] = array();
	$GLOBALS['response'] = null === $code ? new WP_Error() : array( 'code' => $code, 'body' => is_string( $body ) ? $body : json_encode( $body ) );
}
function check( $file = BASENAME ) {
	return LCFF_Updater::check( false, array( 'Version' => LCFF_VERSION ), $file, array( 'de_DE' ) );
}

// Hooks.
LCFF_Updater::init();
$hooks = array_map( function ( $f ) { return array( $f[0], $f[3] ); }, $GLOBALS['filters'] );
t( 'hooks: update_plugins_github.com (4 args) and plugins_api (3 args)', $hooks, array( array( 'update_plugins_github.com', 4 ), array( 'plugins_api', 3 ) ) );

// The check answers only for this plugin, without a request otherwise.
answer( 200, array( 'version' => '0.2.0' ) );
t( 'another plugin: unchanged', check( 'other/other.php' ), false );
t( 'another plugin: no request', count( $GLOBALS['requests'] ), 0 );
t( 'another plugin keeps a value another filter set', LCFF_Updater::check( array( 'version' => '9' ), array(), 'other/other.php', array() ), array( 'version' => '9' ) );

// A good manifest.
answer( 200, array( 'version' => '0.2.0', 'requires' => '6.3', 'requires_php' => '7.4', 'tested' => '7.1' ) );
$u = check();
t( 'fetches the latest release\'s update.json', $GLOBALS['requests'][0][0], MANIFEST_URL );
t( 'with a timeout', isset( $GLOBALS['requests'][0][1]['timeout'] ) && $GLOBALS['requests'][0][1]['timeout'] <= 15, true );
t(
	'update data',
	$u,
	array(
		'slug'         => 'live-chat-for-freescout',
		'version'      => '0.2.0',
		'url'          => 'https://github.com/automaze-me/live-chat-for-freescout/releases/tag/v0.2.0',
		'package'      => 'https://github.com/automaze-me/live-chat-for-freescout/releases/download/v0.2.0/live-chat-for-freescout.zip',
		'requires'     => '6.3',
		'requires_php' => '7.4',
		'tested'       => '7.1',
	)
);

// One fetch an hour serves the check and the details box.
check();
LCFF_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'live-chat-for-freescout' ) );
t( 'cached: one request for three uses', count( $GLOBALS['requests'] ), 1 );
t( 'cached for an hour', $GLOBALS['site_tr']['lcff_update_manifest'][1], 3600 );

// The package comes from the version, whatever the manifest says.
answer( 200, array( 'version' => '0.2.0', 'package' => 'https://evil.example/x.zip', 'url' => 'https://evil.example/' ) );
$u = check();
t( 'a package in the manifest is ignored', $u['package'], 'https://github.com/automaze-me/live-chat-for-freescout/releases/download/v0.2.0/live-chat-for-freescout.zip' );
t( 'a url in the manifest is ignored', $u['url'], 'https://github.com/automaze-me/live-chat-for-freescout/releases/tag/v0.2.0' );

// Optional fields: missing or malformed ones are left out.
answer( 200, array( 'version' => '1.10.3', 'tested' => 'latest', 'requires_php' => '<script>' ) );
$u = check();
t( 'two-digit parts accepted', $u['version'], '1.10.3' );
t( 'malformed optional fields left out', array_keys( $u ), array( 'slug', 'version', 'url', 'package' ) );

// No update when GitHub fails or answers nonsense; the failure is cached too.
foreach ( array(
	'network error'            => array( null, '' ),
	'404'                      => array( 404, 'Not Found' ),
	'500'                      => array( 500, array( 'version' => '0.2.0' ) ),
	'not JSON'                 => array( 200, '<html>' ),
	'a JSON list'              => array( 200, array( '0.2.0' ) ),
	'no version'               => array( 200, array( 'tested' => '7.1' ) ),
	'a version with a v'       => array( 200, array( 'version' => 'v0.2.0' ) ),
	'two-part version'         => array( 200, array( 'version' => '0.2' ) ),
	'a version with a path'    => array( 200, array( 'version' => '0.2.0/../../x' ) ),
	'a pre-release suffix'     => array( 200, array( 'version' => '0.2.0-beta' ) ),
	'a numeric version'        => array( 200, '{"version":0.2}' ),
) as $case => $a ) {
	answer( $a[0], $a[1] );
	t( "$case: no update", check(), false );
	check();
	t( "$case: one request (failure cached)", count( $GLOBALS['requests'] ), 1 );
}

// The details box: only for this plugin's slug and the information action.
answer( 200, array( 'version' => '0.2.0', 'requires' => '6.3', 'requires_php' => '7.4', 'tested' => '7.1' ) );
t( 'info: other action unchanged', LCFF_Updater::info( false, 'query_plugins', (object) array( 'slug' => 'live-chat-for-freescout' ) ), false );
t( 'info: other slug unchanged', LCFF_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ), false );
t( 'info: no slug unchanged', LCFF_Updater::info( false, 'plugin_information', (object) array() ), false );
t( 'info: no request for others', count( $GLOBALS['requests'] ), 0 );
$i = LCFF_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'live-chat-for-freescout' ) );
t( 'info: an object', is_object( $i ), true );
t(
	'info: fields',
	array( $i->name, $i->slug, $i->version, $i->download_link, $i->homepage, $i->requires, $i->requires_php, $i->tested ),
	array( 'Live Chat for FreeScout', 'live-chat-for-freescout', '0.2.0', 'https://github.com/automaze-me/live-chat-for-freescout/releases/download/v0.2.0/live-chat-for-freescout.zip', 'https://github.com/automaze-me/live-chat-for-freescout', '6.3', '7.4', '7.1' )
);
t( 'info: description section', isset( $i->sections['description'] ) && false !== strpos( $i->sections['description'], 'FreeScout' ), true );
t( 'info: changelog links the release notes', isset( $i->sections['changelog'] ) && false !== strpos( $i->sections['changelog'], 'href="https://github.com/automaze-me/live-chat-for-freescout/releases/tag/v0.2.0"' ), true );
answer( 404, '' );
t( 'info: GitHub down, unchanged', LCFF_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'live-chat-for-freescout' ) ), false );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
