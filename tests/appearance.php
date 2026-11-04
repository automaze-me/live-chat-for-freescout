<?php
/**
 * Appearance settings: sanitizing and hidden-page matching.
 * Run: php tests/appearance.php
 */

define( 'ABSPATH', __DIR__ . '/' );

// Tiny stand-ins for the WordPress functions the pure logic calls.
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function sanitize_text_field( $v ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function esc_url_raw( $url, $protocols = null ) {
	$p = parse_url( $url, PHP_URL_SCHEME );
	if ( ! $p || ( $protocols && ! in_array( $p, $protocols, true ) ) ) {
		return '';
	}
	return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
}
function absint( $v ) { return abs( (int) $v ); }

require __DIR__ . '/../includes/class-lcff-appearance.php';

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

t( 'accent red', LCFF_Appearance::sanitize( array( 'accent' => 'red' ) )['accent'], '#2f6f5e' );
t( 'accent bad hex', LCFF_Appearance::sanitize( array( 'accent' => '#12345g' ) )['accent'], '#2f6f5e' );
t( 'accent ok, lowercased', LCFF_Appearance::sanitize( array( 'accent' => '#AABBCC' ) )['accent'], '#aabbcc' );
t( 'accent 3-digit rejected', LCFF_Appearance::sanitize( array( 'accent' => '#abc' ) )['accent'], '#2f6f5e' );
t( 'position left', LCFF_Appearance::sanitize( array( 'position' => 'left' ) )['position'], 'left' );
t( 'position other', LCFF_Appearance::sanitize( array( 'position' => 'top' ) )['position'], 'right' );
t( 'team name cut to 60', LCFF_Appearance::sanitize( array( 'team_name' => str_repeat( 'a', 80 ) ) )['team_name'], str_repeat( 'a', 60 ) );
t( 'team name multibyte cut', preg_match_all( '/./u', LCFF_Appearance::sanitize( array( 'team_name' => str_repeat( 'ä', 80 ) ) )['team_name'] ), 60 );
t( 'welcome cut to 500', strlen( LCFF_Appearance::sanitize( array( 'welcome' => str_repeat( 'b', 900 ) ) )['welcome'] ), 500 );
t( 'welcome_away cut to 500', strlen( LCFF_Appearance::sanitize( array( 'welcome_away' => str_repeat( 'b', 900 ) ) )['welcome_away'] ), 500 );
t( 'avatar id int', LCFF_Appearance::sanitize( array( 'avatar_id' => '42' ) )['avatar_id'], 42 );
t( 'avatar id junk', LCFF_Appearance::sanitize( array( 'avatar_id' => 'x' ) )['avatar_id'], 0 );
t(
	'hidden paths normalized',
	LCFF_Appearance::sanitize( array( 'hidden_paths' => "  /checkout \r\n\r\ncart/\n  \n/shop/*  " ) )['hidden_paths'],
	"/checkout\n/cart/\n/shop/*"
);
t( 'privacy http(s) only', LCFF_Appearance::sanitize( array( 'privacy_url' => 'javascript:alert(1)' ) )['privacy_url'], '' );
t( 'privacy ftp rejected', LCFF_Appearance::sanitize( array( 'privacy_url' => 'ftp://x.test/p' ) )['privacy_url'], '' );
t( 'privacy https ok', LCFF_Appearance::sanitize( array( 'privacy_url' => 'https://x.test/privacy' ) )['privacy_url'], 'https://x.test/privacy' );
t( 'absent keys stay absent', LCFF_Appearance::sanitize( array( 'position' => 'left' ) ), array( 'position' => 'left' ) );

t( 'hidden: trailing slash ignored', LCFF_Appearance::is_hidden( '/checkout/', '/checkout' ), true );
t( 'hidden: sub path not matched by exact', LCFF_Appearance::is_hidden( '/checkout/pay', '/checkout' ), false );
t( 'hidden: prefix star', LCFF_Appearance::is_hidden( '/checkout/pay', '/checkout/*' ), true );
t( 'hidden: prefix star matches the folder itself', LCFF_Appearance::is_hidden( '/checkout/', '/checkout/*' ), true );
t( 'hidden: prefix star not sibling', LCFF_Appearance::is_hidden( '/checkout-2', '/checkout/*' ), false );
t( 'hidden: root, empty list', LCFF_Appearance::is_hidden( '/', '' ), false );
t( 'hidden: root listed', LCFF_Appearance::is_hidden( '/', '/' ), true );
t( 'hidden: second line', LCFF_Appearance::is_hidden( '/cart', "/checkout\n/cart/" ), true );
t( 'hidden: case sensitive paths kept', LCFF_Appearance::is_hidden( '/Cart', '/cart' ), false );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
