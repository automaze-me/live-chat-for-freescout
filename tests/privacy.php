<?php
/**
 * LCFF_Privacy: WP Consent API registration, the suggested privacy policy
 * text, and the readme's list of browser storage.
 * Run: php tests/privacy.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LCFF_PLUGIN_FILE', '/srv/wp/wp-content/plugins/live-chat-for-freescout/live-chat-for-freescout.php' );

$GLOBALS['lcff_filters']  = array();
$GLOBALS['lcff_actions']  = array();
$GLOBALS['lcff_cookies']  = array();
$GLOBALS['lcff_policies'] = array();
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['lcff_filters'][ $hook ] = $cb; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['lcff_actions'][ $hook ][] = $cb; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function __return_true() { return true; }
function __( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_option( $name, $default = false ) {
	return array( 'freescout_url' => 'https://help.example.test:8443/sub', 'site_id' => str_repeat( 'ab', 16 ) );
}

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
set_error_handler( function ( $no, $msg, $file, $line ) {
	global $ok;
	$ok = false;
	echo "FAIL PHP warning: $msg ($file:$line)\n";
	return true;
} );

function wp_strip_all_tags( $v ) { return trim( strip_tags( (string) $v ) ); }
require __DIR__ . '/../includes/class-lcff-appearance.php';
require __DIR__ . '/../includes/class-lcff-settings.php';
require __DIR__ . '/../includes/class-lcff-privacy.php';

/* ---- init ---- */

LCFF_Privacy::init();
t( 'register_consent runs on plugins_loaded', isset( $GLOBALS['lcff_actions']['plugins_loaded'] ) && in_array( array( 'LCFF_Privacy', 'register_consent' ), $GLOBALS['lcff_actions']['plugins_loaded'], true ), true );
t( 'add_policy runs on admin_init', isset( $GLOBALS['lcff_actions']['admin_init'] ) && in_array( array( 'LCFF_Privacy', 'add_policy' ), $GLOBALS['lcff_actions']['admin_init'], true ), true );

/* ---- consent registration ---- */

// Without the WP Consent API: nothing happens, nothing breaks.
LCFF_Privacy::register_consent();
$hook = 'wp_consent_api_registered_live-chat-for-freescout/live-chat-for-freescout.php';
t( 'consent filter registered', isset( $GLOBALS['lcff_filters'][ $hook ] ) ? $GLOBALS['lcff_filters'][ $hook ] : null, '__return_true' );
t( 'consent filter returns true', call_user_func( $GLOBALS['lcff_filters'][ $hook ] ), true );
t( 'no cookie info without wp_add_cookie_info()', $GLOBALS['lcff_cookies'], array() );

// Declared inside a block so PHP defines them here, not at compile time.
if ( true ) {
	// The real signature (wp-consent-api 2.0.1, inc/api-functions.php): five required parameters.
	function wp_add_cookie_info( $name, $plugin_or_service, $category, $expires, $function, $collected_personal_data = '', $member_cookie = false, $administrator_cookie = false, $type = 'HTTP', $domain = false ) {
		if ( ! empty( $GLOBALS['lcff_cookie_throws'] ) ) {
			throw new TypeError( 'wp_add_cookie_info() changed' );
		}
		$GLOBALS['lcff_cookies'][] = array( $name, $plugin_or_service, $category, $expires, $function, $collected_personal_data );
	}
}
LCFF_Privacy::register_consent();
t(
	'lcff_logout registered as functional, with expiry and purpose',
	$GLOBALS['lcff_cookies'],
	array( array( 'lcff_logout', 'Live Chat for FreeScout', 'functional', '10 minutes', 'Tells the chat to close the conversation in this browser after you log out of this site.', '' ) )
);
$GLOBALS['lcff_cookie_throws'] = true;
$threw = false;
try {
	LCFF_Privacy::register_consent();
} catch ( Throwable $e ) {
	$threw = true;
}
t( 'register_consent survives a failing wp_add_cookie_info()', $threw, false );
$GLOBALS['lcff_cookie_throws'] = false;

/* ---- policy text ---- */

$text = LCFF_Privacy::policy_text();
t( 'policy names the FreeScout host', false !== strpos( $text, 'help.example.test' ) && false === strpos( $text, ':8443' ) && false === strpos( $text, 'https://' ), true );
foreach ( array( 'lcff_token', 'lcff_seen', 'lcff_ask', 'lcff_acct', 'lcff_page', 'lcff_logout' ) as $key ) {
	t( "policy names $key", false !== strpos( $text, $key ), true );
}
t( 'policy mentions the order', false !== strpos( $text, 'order' ), true );
t( 'policy mentions the user ID', false !== strpos( $text, 'user ID' ), true );
t( 'policy has the tutorial paragraph', false !== strpos( $text, '<p class="privacy-policy-tutorial">Add how long your help desk keeps chat conversations.</p>' ), true );
t( 'policy has no stray %s', false === strpos( $text, '%s' ), true );
t(
	'policy says what the chat stores',
	false !== strpos( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ), "The chat stores nothing in your browser until you use it: you send a message, you continue a conversation from an email link, or, if you are logged in and your account already has conversations, you open the chat. Then it keeps a random chat token and a few small settings in your browser's storage" ),
	true
);

/* ---- add_policy ---- */

LCFF_Privacy::add_policy();
t( 'add_policy without the function does nothing', $GLOBALS['lcff_policies'], array() );
if ( true ) {
	function wp_add_privacy_policy_content( $name, $content ) { $GLOBALS['lcff_policies'][] = array( $name, $content ); }
}
LCFF_Privacy::add_policy();
t( 'add_policy registers once under the plugin name', count( $GLOBALS['lcff_policies'] ), 1 );
t( 'add_policy name', $GLOBALS['lcff_policies'][0][0], 'Live Chat for FreeScout' );
t( 'add_policy content is policy_text()', $GLOBALS['lcff_policies'][0][1], $text );

/* ---- the readme lists every storage key the scripts use ---- */

$root   = dirname( __DIR__ );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$keys   = array();
foreach ( glob( $root . '/assets/js/*.js' ) as $file ) {
	$js = (string) file_get_contents( $file );
	// Keys written as string literals next to a storage call, or in the loader's
	// list of every key (window.LCFF_STORAGE: `local: [...]`, `session: [...]`).
	preg_match_all( "/(?:localStorage|sessionStorage|store)\\.?\\w*\\(\\s*'(lcff_[a-z_]+)'/", $js, $m );
	$keys = array_merge( $keys, $m[1] );
	preg_match_all( "/(?:keys\\s*=|local\\s*:|session\\s*:)\\s*\\[([^\\]]*)\\]/", $js, $lists );
	foreach ( $lists[1] as $list ) {
		preg_match_all( "/'(lcff_[a-z_]+)'/", $list, $mm );
		$keys = array_merge( $keys, $mm[1] );
	}
	preg_match_all( "/document\\.cookie[^;]*?(lcff_[a-z_]+)=/", $js, $c );
	$keys = array_merge( $keys, $c[1] );
}
$keys = array_unique( $keys );
sort( $keys );
t( 'the scan found the scripts\' storage keys', count( $keys ) >= 5, true );
foreach ( $keys as $key ) {
	t( "readme lists $key", false !== strpos( $readme, '`' . $key . '`' ), true );
}
foreach ( array( 'lcff_token', 'lcff_seen', 'lcff_ask', 'lcff_acct', 'lcff_page', 'lcff_logout' ) as $key ) {
	t( "readme lists the fixed key $key", false !== strpos( $readme, '`' . $key . '`' ), true );
}
t( 'readme has External services', 1 === preg_match( '/^== External services ==$/m', $readme ), true );
t( 'readme has Privacy', 1 === preg_match( '/^== Privacy ==$/m', $readme ), true );
t( 'readme links FreeScout', false !== strpos( $readme, 'https://freescout.net' ), true );
t( 'readme links the module repo', false !== strpos( $readme, 'https://github.com/automaze-me/freescout-wordpresschat' ), true );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
