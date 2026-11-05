<?php
/**
 * LCFF_Connection::handle_ajax(): nonce and capability checks.
 * Run: php tests/connection-ajax.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class LCFF_Test_Json extends Exception {
	public $payload;
	public $status;
}

$GLOBALS['lcff_nonce_ok'] = true;
$GLOBALS['lcff_can']      = true;

function __( $text, $domain = null ) { return $text; }
function get_option( $name, $default = false ) { return $default; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function nocache_headers() {}
function check_ajax_referer( $action = -1, $arg = false, $die = true ) {
	if ( false !== $die ) {
		die( "FAIL check_ajax_referer must be called with die = false\n" );
	}
	return $GLOBALS['lcff_nonce_ok'] ? 1 : false;
}
function current_user_can( $cap ) { return $GLOBALS['lcff_can'] && 'manage_options' === $cap; }
function lcff_test_throw( $payload, $status ) {
	$e          = new LCFF_Test_Json( 'json' );
	$e->payload = $payload;
	$e->status  = $status;
	throw $e;
}
function wp_send_json( $data, $status = 200 ) { lcff_test_throw( $data, $status ); }
function wp_send_json_error( $data = null, $status = 200 ) { lcff_test_throw( array( 'success' => false, 'data' => $data ), $status ); }

function wp_strip_all_tags( $v ) { return trim( strip_tags( (string) $v ) ); }
require __DIR__ . '/../includes/class-lcff-appearance.php';
require __DIR__ . '/../includes/class-lcff-crypto.php';
require __DIR__ . '/../includes/class-lcff-settings.php';
require __DIR__ . '/../includes/class-lcff-connection.php';

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
function run() {
	try {
		LCFF_Connection::handle_ajax();
	} catch ( LCFF_Test_Json $e ) {
		return array( $e->status, $e->payload );
	}
	return array( null, null );
}

$GLOBALS['lcff_nonce_ok'] = false;
$r = run();
t( 'bad nonce: 403', $r[0], 403 );
t( 'bad nonce: session expired text', $r[1], array( 'success' => false, 'data' => array( 'message' => 'Your session has expired. Reload the page and try again.' ) ) );

$GLOBALS['lcff_nonce_ok'] = true;
$GLOBALS['lcff_can']      = false;
$r = run();
t( 'no manage_options: 403', $r[0], 403 );
t( 'no manage_options: no ok', isset( $r[1]['ok'] ), false );

$GLOBALS['lcff_can'] = true;
$r = run();
t( 'admin reaches test()', array( $r[0], $r[1]['ok'], $r[1]['message'] ), array( 200, false, 'Save the FreeScout URL and Site ID first.' ) );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
