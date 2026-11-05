<?php
/**
 * The settings screen markup: secret field, hours rows, no inline styles.
 * Run: php tests/settings-render.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function checked( $a, $b = true, $echo = true ) { return $a === $b ? ' checked=\'checked\'' : ''; }
function selected( $a, $b = true, $echo = true ) { return $a === $b ? ' selected=\'selected\'' : ''; }
function wp_timezone_string() { return 'Europe/Berlin'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function current_user_can( $cap ) { return true; }
function get_admin_page_title() { return 'Live Chat for FreeScout'; }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . $g . '" />'; }
function do_settings_sections( $p ) { echo '<div class="lcff-sections"></div>'; }
function submit_button() { echo '<input type="submit" class="button button-primary" value="Save" />'; }
function wp_get_attachment_image_url( $id, $size ) { return $id ? 'https://x.test/a.png' : ''; }

$secret = str_repeat( 'Ab1_-', 8 ) . 'xyz';
$GLOBALS['lcff_stored'] = array(
	'freescout_url' => 'https://help.example',
	'site_id'       => '0123456789abcdef0123456789abcdef',
	'secret'        => $secret,
	'avatar_id'     => 5,
	'hours'         => array( array( '09:00', '17:00' ), null, null, null, null, null, null ),
);
function get_option( $name, $default = false ) { return $GLOBALS['lcff_stored']; }

function wp_strip_all_tags( $v ) { return trim( strip_tags( (string) $v ) ); }
require __DIR__ . '/../includes/class-lcff-appearance.php';
require __DIR__ . '/../includes/class-lcff-crypto.php';
require __DIR__ . '/../includes/class-lcff-settings.php';

$ok = true;
function t( $name, $cond ) {
	global $ok;
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	if ( ! $cond ) {
		$ok = false;
	}
}
function out( $fn, $args = array() ) {
	ob_start();
	call_user_func_array( $fn, $args );
	return ob_get_clean();
}

$field = out( array( 'LCFF_Settings', 'render_field' ), array( array( 'key' => 'secret', 'type' => 'password', 'placeholder' => '', 'description' => 'd' ) ) );
t( 'secret input is a password input', false !== strpos( $field, 'type="password"' ) );
t( 'secret value never in the markup', false === strpos( $field, $secret ) );
t( 'secret field has no style attribute', false === strpos( $field, 'style=' ) );

$bad                        = $GLOBALS['lcff_stored'];
$GLOBALS['lcff_stored']['secret'] = 'short';
$field = out( array( 'LCFF_Settings', 'render_field' ), array( array( 'key' => 'secret', 'type' => 'password', 'placeholder' => '', 'description' => '' ) ) );
t( 'invalid secret note has no style attribute', false !== strpos( $field, 'not valid' ) && false === strpos( $field, 'style=' ) );
$GLOBALS['lcff_stored'] = $bad;

$hours = out( array( 'LCFF_Settings', 'render_hours_field' ) );
t( 'seven hours rows', 7 === substr_count( $hours, 'lcff-hours-row' ) );
foreach ( array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ) as $day ) {
	t( "$day: open, opening and closing time labels", false !== strpos( $hours, "$day: open" ) && false !== strpos( $hours, "$day: opening time" ) && false !== strpos( $hours, "$day: closing time" ) );
}
t( 'day text is screen-reader text', false !== strpos( $hours, '<span class="screen-reader-text">Monday: opening time</span>' ) );
t( 'hours field has no style attribute', false === strpos( $hours, 'style=' ) );

foreach ( array( 'avatar', 'accent', 'position', 'textarea', 'text' ) as $type ) {
	$o = out( array( 'LCFF_Settings', 'render_appearance_field' ), array( array( 'key' => 'avatar' === $type ? 'avatar_id' : 'team_name', 'type' => $type, 'description' => 'x' ) ) );
	t( "appearance field $type has no style attribute", false === strpos( $o, 'style=' ) );
}
$GLOBALS['lcff_stored']['avatar_id'] = 0;
$o = out( array( 'LCFF_Settings', 'render_appearance_field' ), array( array( 'key' => 'avatar_id', 'type' => 'avatar', 'description' => '' ) ) );
t( 'empty avatar has no style attribute', false === strpos( $o, 'style=' ) && false !== strpos( $o, 'lcff-hidden' ) );

$page = out( array( 'LCFF_Settings', 'render_page' ) );
t( 'page has the test button and result', false !== strpos( $page, 'id="lcff_test_connection"' ) && false !== strpos( $page, 'id="lcff_test_result"' ) );
t( 'page has no style attribute', false === strpos( $page, 'style=' ) );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
