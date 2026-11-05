<?php
/**
 * Working-hours sanitizing.
 * Run: php tests/hours-settings.php
 */

define( 'ABSPATH', __DIR__ . '/' );

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

$r = LCFF_Settings::sanitize_hours( array( array( '09:00', '17:00' ), null, array( '08:30', '12:00' ) ) );
t( 'always 7 entries', count( $r ), 7 );
t( 'keeps a valid range', $r[0], array( '09:00', '17:00' ) );
t( 'null stays null', $r[1], null );
t( 'second valid range', $r[2], array( '08:30', '12:00' ) );
t( 'missing days are closed', $r[6], null );
t( 'from >= to', LCFF_Settings::sanitize_hours( array( array( '17:00', '09:00' ) ) )[0], null );
t( 'from == to', LCFF_Settings::sanitize_hours( array( array( '09:00', '09:00' ) ) )[0], null );
t( 'single-digit hour', LCFF_Settings::sanitize_hours( array( array( '9:00', '17:00' ) ) )[0], null );
t( 'hour 24', LCFF_Settings::sanitize_hours( array( array( '24:00', '25:00' ) ) )[0], null );
t( 'minute 60', LCFF_Settings::sanitize_hours( array( array( '09:60', '17:00' ) ) )[0], null );
t( 'junk entry', LCFF_Settings::sanitize_hours( array( 'x' ) )[0], null );
t( 'extra entries dropped', count( LCFF_Settings::sanitize_hours( array_fill( 0, 10, array( '09:00', '10:00' ) ) ) ), 7 );
t( 'empty input', LCFF_Settings::sanitize_hours( array() ), array_fill( 0, 7, null ) );

$f = LCFF_Settings::hours_from_form( array( array( 'open' => '1', 'from' => '09:00', 'to' => '17:00' ), array( 'from' => '09:00', 'to' => '17:00' ) ) );
t( 'form: checked kept', $f[0], array( '09:00', '17:00' ) );
t( 'form: unchecked closed', $f[1], null );

$f = LCFF_Settings::hours_from_form( array( array( 'open' => '1', 'from' => '00:00', 'to' => '00:00' ) ) );
t( 'form: open, 00:00 to 00:00 is the full day', $f[0], array( '00:00', '24:00' ) );
t( 'full day survives sanitize_hours', LCFF_Settings::sanitize_hours( $f )[0], array( '00:00', '24:00' ) );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
