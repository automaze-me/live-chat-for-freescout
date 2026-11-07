<?php
/**
 * The settings sanitizer is idempotent: WordPress runs it twice on the first
 * save (update_option() sanitizes, then add_option() sanitizes the result
 * again when the option does not exist yet, WP trac #21989), and options.php
 * has already unslashed the form before either pass.
 * Run: php tests/settings-sanitize.php
 */

define( 'ABSPATH', __DIR__ . '/' );

// Tiny stand-ins for the WordPress functions the sanitizer calls.
$GLOBALS['lcff_test_option'] = null; // null: the option does not exist
function get_option( $name, $default = false ) {
	return null === $GLOBALS['lcff_test_option'] ? $default : $GLOBALS['lcff_test_option'];
}
function wp_unslash( $v ) {
	if ( is_array( $v ) ) {
		return array_map( 'wp_unslash', $v );
	}
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function wp_slash( $v ) {
	if ( is_array( $v ) ) {
		return array_map( 'wp_slash', $v );
	}
	return is_string( $v ) ? addslashes( $v ) : $v;
}
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
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
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function __( $text, $domain = null ) { return $text; }
$GLOBALS['lcff_test_errors'] = array();
function add_settings_error( $setting, $code, $message ) { $GLOBALS['lcff_test_errors'][] = $code; }

function wp_strip_all_tags( $v ) { return trim( strip_tags( (string) $v ) ); }
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

/*
 * What options.php + update_option() do with a submitted form: $_POST is
 * slashed (wp_magic_quotes), options.php unslashes it, update_option()
 * sanitizes, and when the option does not exist yet add_option() sanitizes
 * that result once more.
 */
function wp_save_from_form( array $raw ) {
	$post  = wp_slash( $raw );
	$value = wp_unslash( $post );
	$value = LCFF_Settings::sanitize( $value );
	if ( null === $GLOBALS['lcff_test_option'] ) {
		$value = LCFF_Settings::sanitize( $value );
	}
	$GLOBALS['lcff_test_option'] = $value;
	return $value;
}

$secret  = str_repeat( 'Ab1_-', 8 ) . 'xyz'; // 43 characters
$welcome = 'Questions? Write to us. Paths like C:\Shop\Orders are fine \o/';
$closed  = array( 'from' => '09:00', 'to' => '17:00' ); // an unchecked day still posts its times
$form    = array(
	'freescout_url' => 'https://support.example.test/',
	'site_id'       => '0123456789abcdef0123456789abcdef',
	'secret'        => '',
	'team_name'     => 'Shop \ Team',
	'avatar_id'     => '12',
	'accent'        => '#AA3311',
	'button_color'  => '#BA3B46',
	'button_ring'   => '1',
	'position'      => 'left',
	'welcome'       => $welcome,
	'welcome_away'  => "Away.\nBack soon \\ promise",
	'hidden_paths'  => "/checkout/\n/account/*",
	'privacy_url'   => 'https://shop.example.test/privacy/',
	'hours'         => array(
		array( 'open' => '1', 'from' => '09:00', 'to' => '17:00' ),
		$closed,
		array( 'open' => '1', 'from' => '08:30', 'to' => '12:00' ),
		$closed,
		array( 'open' => '1', 'from' => '10:00', 'to' => '10:30' ),
		$closed,
		$closed,
	),
);
$hours = array( array( '09:00', '17:00' ), null, array( '08:30', '12:00' ), null, array( '10:00', '10:30' ), null, null );

// 1. A fresh install: the option does not exist, so WordPress sanitizes twice.
$GLOBALS['lcff_test_option'] = null;
$fresh = $form;
$fresh['secret'] = $secret;
$saved = wp_save_from_form( $fresh );
t( 'first save keeps the working hours', $saved['hours'], $hours );
t( 'first save keeps a backslash in the welcome text', $saved['welcome'], $welcome );
t( 'first save keeps a backslash in the away text', $saved['welcome_away'], "Away.\nBack soon \\ promise" );
t( 'first save keeps a backslash in the team name', $saved['team_name'], 'Shop \ Team' );
t( 'first save stores the secret', $saved['secret'], $secret );
t( 'first save, the rest', array( $saved['freescout_url'], $saved['site_id'], $saved['avatar_id'], $saved['accent'], $saved['position'], $saved['hidden_paths'], $saved['privacy_url'] ), array( 'https://support.example.test', '0123456789abcdef0123456789abcdef', 12, '#aa3311', 'left', "/checkout/\n/account/*", 'https://shop.example.test/privacy/' ) );

t( 'first save keeps the button color and the ring', array( $saved['button_color'], $saved['button_ring'] ), array( '#ba3b46', true ) );
$GLOBALS['lcff_test_option'] = $saved;
$off = wp_save_from_form( array_merge( $form, array( 'button_color' => '', 'button_ring' => '0' ) ) );
t( 'an unchecked ring and an empty button color are saved', array( $off['button_color'], $off['button_ring'] ), array( '', false ) );

// 2. sanitize(sanitize($form)) === sanitize($form), with a saved secret and the field left blank.
$GLOBALS['lcff_test_option'] = array( 'secret' => $secret, 'site_id' => '0123456789abcdef0123456789abcdef' );
$once  = LCFF_Settings::sanitize( $form );
$twice = LCFF_Settings::sanitize( $once );
t( 'sanitize is idempotent', $twice, $once );
t( 'a blank secret keeps the saved one', $once['secret'], $secret );
t( 'hours after one pass', $once['hours'], $hours );
t( 'welcome after one pass', $once['welcome'], $welcome );
t( 'no settings errors', $GLOBALS['lcff_test_errors'], array() );

// 3. Saving again over an existing option is a single pass and keeps it all.
$GLOBALS['lcff_test_option'] = $saved;
$again = wp_save_from_form( $form );
t( 'second save over the existing option is unchanged', $again, $saved );

// 4. The hours rows: form rows, already-sanitized rows, junk.
t( 'sanitized rows pass through', LCFF_Settings::hours_from_form( $hours ), $hours );
t( 'a form row without the checkbox is closed', LCFF_Settings::hours_from_form( array( $closed ) ), array( null ) );
t( 'an invalid list row is closed after sanitize_hours', LCFF_Settings::sanitize_hours( LCFF_Settings::hours_from_form( array( array( '17:00', '09:00' ) ) ) )[0], null );
t( 'a three-element list is closed', LCFF_Settings::hours_from_form( array( array( '09:00', '10:00', '11:00' ) ) ), array( null ) );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
