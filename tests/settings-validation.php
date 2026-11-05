<?php
/**
 * Settings validation: URL, array values, clearable secret, hours ranges,
 * decoded texts.
 * Run: php tests/settings-validation.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lcff_test_option'] = null;
function get_option( $name, $default = false ) {
	return null === $GLOBALS['lcff_test_option'] ? $default : $GLOBALS['lcff_test_option'];
}
function sanitize_text_field( $v ) {
	if ( is_array( $v ) ) {
		return '';
	}
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', htmlspecialchars( strip_tags( (string) $v ), ENT_NOQUOTES ) ) );
}
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function esc_url_raw( $url, $protocols = null ) {
	$p = parse_url( $url, PHP_URL_SCHEME );
	if ( ! $p || ( $protocols && ! in_array( $p, $protocols, true ) ) ) {
		return '';
	}
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}
function untrailingslashit( $v ) { return rtrim( $v, '/\\' ); }
function absint( $v ) { return abs( (int) $v ); }
function __( $text, $domain = null ) { return $text; }
function wp_attachment_stub() {}
function wp_get_attachment_image_url() { return ''; }
function get_privacy_policy_url() { return ''; }
$GLOBALS['lcff_test_errors'] = array();
function add_settings_error( $setting, $code, $message ) { $GLOBALS['lcff_test_errors'][] = array( $code, $message ); }
$GLOBALS['lcff_https'] = true;
function is_ssl() { return $GLOBALS['lcff_https']; }
function home_url() { return $GLOBALS['lcff_https'] ? 'https://shop.example.test' : 'http://shop.example.test'; }

require __DIR__ . '/../includes/class-lcff-appearance.php';
require __DIR__ . '/../includes/class-lcff-settings.php';

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
set_error_handler( function ( $no, $msg ) {
	global $ok;
	$ok = false;
	echo "FAIL PHP warning: $msg\n";
	return true;
} );

// URLs.
t( 'relative URL is invalid', LCFF_Settings::validate_url( '/freescout', true ), array( '', 'invalid' ) );
t( 'garbage is invalid', LCFF_Settings::validate_url( 'not a url', false ), array( '', 'invalid' ) );
t( 'http on an https site', LCFF_Settings::validate_url( 'http://help.example', true ), array( '', 'https_required' ) );
t( 'https, trailing slash dropped', LCFF_Settings::validate_url( 'https://help.example/', true ), array( 'https://help.example', null ) );
t( 'http on an http site', LCFF_Settings::validate_url( 'http://help.example', false ), array( 'http://help.example', null ) );
t( 'http localhost on an https site', LCFF_Settings::validate_url( 'http://localhost:8090', true ), array( 'http://localhost:8090', null ) );
t( 'http 127.0.0.1 on an https site', LCFF_Settings::validate_url( 'http://127.0.0.1:8090/', true ), array( 'http://127.0.0.1:8090', null ) );
t( 'http [::1] on an https site', LCFF_Settings::validate_url( 'http://[::1]:8090', true ), array( 'http://[::1]:8090', null ) );
t( 'localhost host is case-insensitive', LCFF_Settings::validate_url( 'http://LocalHost', true ), array( 'http://LocalHost', null ) );
t( 'localhost.example.com is not loopback', LCFF_Settings::validate_url( 'http://localhost.example.com', true ), array( '', 'https_required' ) );
t( 'empty is fine', LCFF_Settings::validate_url( '', true ), array( '', null ) );

$secret = str_repeat( 'Ab1_-', 8 ) . 'xyz';
$site   = '0123456789abcdef0123456789abcdef';
$saved  = array( 'freescout_url' => 'https://old.example', 'site_id' => $site, 'secret' => $secret );

// sanitize() keeps the previous URL on an error and raises the copy.
$GLOBALS['lcff_test_option'] = $saved;
$out = LCFF_Settings::sanitize( array( 'freescout_url' => 'http://help.example' ) );
t( 'https_required keeps the previous URL', $out['freescout_url'], 'https://old.example' );
t( 'https_required message', $GLOBALS['lcff_test_errors'], array( array( 'lcff_url_https', 'Your site uses HTTPS, so the FreeScout URL must start with https://.' ) ) );
$GLOBALS['lcff_test_errors'] = array();
$out = LCFF_Settings::sanitize( array( 'freescout_url' => '/freescout' ) );
t( 'invalid keeps the previous URL', $out['freescout_url'], 'https://old.example' );
t( 'invalid message', $GLOBALS['lcff_test_errors'], array( array( 'lcff_url', 'The FreeScout URL is not valid.' ) ) );
$GLOBALS['lcff_test_errors'] = array();
$GLOBALS['lcff_https']       = false;
$out = LCFF_Settings::sanitize( array( 'freescout_url' => 'http://help.example' ) );
t( 'http accepted on an http site', array( $out['freescout_url'], $GLOBALS['lcff_test_errors'] ), array( 'http://help.example', array() ) );
$GLOBALS['lcff_https'] = true;

// Arrays posted for text fields.
$out = LCFF_Settings::sanitize( array( 'team_name' => array( 'x' ), 'welcome' => array( 'y' ), 'accent' => array(), 'privacy_url' => array( 'z' ), 'hidden_paths' => array( 'a' ), 'site_id' => array( 'b' ), 'secret' => array( 'c' ), 'freescout_url' => array( 'd' ) ) );
t( 'array team name is empty', $out['team_name'], '' );
t( 'array welcome is empty', $out['welcome'], '' );
t( 'array privacy url is empty', $out['privacy_url'], '' );
t( 'array site id is empty', $out['site_id'], '' );
$GLOBALS['lcff_test_errors'] = array();

// Clear the secret.
$out = LCFF_Settings::sanitize( array( 'secret' => '', 'secret_clear' => '1' ) );
t( 'secret_clear empties the secret', $out['secret'], '' );
$out = LCFF_Settings::sanitize( array( 'secret' => '' ) );
t( 'no checkbox keeps the secret', $out['secret'], $secret );

// Hours.
$GLOBALS['lcff_test_errors'] = array();
$rows = array_fill( 0, 7, array( 'from' => '09:00', 'to' => '17:00' ) );
$rows[1] = array( 'open' => '1', 'from' => '17:00', 'to' => '09:00' );
$rows[2] = array( 'open' => '1', 'from' => '09:00', 'to' => '24:00' );
$out = LCFF_Settings::sanitize( array( 'hours' => $rows ) );
t( 'bad range closes the day', $out['hours'][1], null );
t( '24:00 end is kept', $out['hours'][2], array( '09:00', '24:00' ) );
t( 'one hours error, with the day', $GLOBALS['lcff_test_errors'], array( array( 'lcff_hours_1', 'Working hours for Tuesday: the end must be later than the start. The day was saved as closed.' ) ) );
$GLOBALS['lcff_test_errors'] = array();
$again = LCFF_Settings::sanitize( $out );
t( 'second pass: same hours, no new error', array( $again['hours'], $GLOBALS['lcff_test_errors'] ), array( $out['hours'], array() ) );
$rows = array_fill( 0, 7, array( 'from' => '09:00', 'to' => '17:00' ) );
$rows[0] = array( 'open' => '1', 'from' => '09:00', 'to' => '00:00' );
t( 'a 00:00 end means midnight', LCFF_Settings::sanitize( array( 'hours' => $rows ) )['hours'][0], array( '09:00', '24:00' ) );

// Entities in front-end texts.
$cfg = LCFF_Appearance::config( array( 'team_name' => 'Fish &amp; Chips &lt;3', 'welcome' => 'a &amp; b', 'welcome_away' => 'x &quot;y&quot;' ) );
t( 'team name decoded', $cfg['teamName'], 'Fish & Chips <3' );
t( 'welcome decoded', $cfg['welcome'], 'a & b' );
t( 'away decoded', $cfg['welcomeAway'], 'x "y"' );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
