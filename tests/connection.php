<?php
/**
 * LCFF_Connection::interpret() maps FreeScout's answer to the admin message.
 * Run: php tests/connection.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $m;
	public function __construct( $code = '', $message = '' ) {
		$this->m = $message;
	}
	public function get_error_message() {
		return $this->m;
	}
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}
function __( $text, $domain = null ) { return $text; }
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; }

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
function resp( $code, $body ) {
	return array(
		'response' => array( 'code' => $code ),
		'body'     => is_string( $body ) ? $body : json_encode( $body ),
	);
}

$home = 'https://shop.test';

t( 'ok with secret', LCFF_Connection::interpret( resp( 200, array( 'ok' => true, 'secret' => 'ok', 'origin' => 'ok' ) ), $home ),
	array( 'ok' => true, 'message' => 'Connected. FreeScout accepted the Site ID and the secret.' ) );
t( 'ok without secret', LCFF_Connection::interpret( resp( 200, array( 'ok' => true, 'secret' => 'missing', 'origin' => null ) ), $home ),
	array( 'ok' => true, 'message' => "Connected. No secret is saved, so logged-in visitors and order numbers won't be recognized." ) );
t( 'secret invalid', LCFF_Connection::interpret( resp( 200, array( 'ok' => true, 'secret' => 'invalid', 'origin' => null ) ), $home ),
	array( 'ok' => false, 'message' => "FreeScout knows this Site ID, but the secret doesn't match. Copy the secret again from FreeScout." ) );
t( 'origin mismatch', LCFF_Connection::interpret( resp( 200, array( 'ok' => true, 'secret' => 'ok', 'origin' => 'mismatch', 'expected_origin' => 'https://old.test' ) ), $home ),
	array( 'ok' => false, 'message' => "FreeScout expects this website at https://old.test, but this site's address is https://shop.test. Correct the address in FreeScout under Manage → Website Chat." ) );
t( '404 not_found', LCFF_Connection::interpret( resp( 404, array( 'error' => 'not_found' ) ), $home ),
	array( 'ok' => false, 'message' => "FreeScout doesn't know this Site ID. Check it under Manage → Website Chat." ) );
t( '404 other body', LCFF_Connection::interpret( resp( 404, '<html>Not Found</html>' ), $home ),
	array( 'ok' => false, 'message' => 'FreeScout answered, but not as expected (HTTP 404). Is the Website Chat module installed and active?' ) );
t( '503 maintenance', LCFF_Connection::interpret( resp( 503, array( 'error' => 'maintenance' ) ), $home ),
	array( 'ok' => false, 'message' => 'FreeScout needs a database update first (System → Tools → Migrate DB).' ) );
t( '503 other', LCFF_Connection::interpret( resp( 503, 'down' ), $home ),
	array( 'ok' => false, 'message' => 'FreeScout answered, but not as expected (HTTP 503). Is the Website Chat module installed and active?' ) );
t( '429', LCFF_Connection::interpret( resp( 429, array( 'error' => 'rate_limited', 'retry_after' => 30 ) ), $home ),
	array( 'ok' => false, 'message' => 'Too many tests. Try again in a minute.' ) );
t( '500', LCFF_Connection::interpret( resp( 500, '' ), $home ),
	array( 'ok' => false, 'message' => 'FreeScout answered, but not as expected (HTTP 500). Is the Website Chat module installed and active?' ) );
t( '200 not json', LCFF_Connection::interpret( resp( 200, '<html>hi</html>' ), $home ),
	array( 'ok' => false, 'message' => 'FreeScout answered, but not as expected (HTTP 200). Is the Website Chat module installed and active?' ) );
t( '200 unknown secret state', LCFF_Connection::interpret( resp( 200, array( 'ok' => true, 'secret' => 'weird' ) ), $home ),
	array( 'ok' => false, 'message' => 'FreeScout answered, but not as expected (HTTP 200). Is the Website Chat module installed and active?' ) );
t( 'transport error', LCFF_Connection::interpret( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ), $home ),
	array( 'ok' => false, 'message' => "Couldn't reach FreeScout: cURL error 7: Failed to connect" ) );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
