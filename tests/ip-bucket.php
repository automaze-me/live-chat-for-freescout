<?php
/**
 * IP bucketing for the order-lookup rate limit.
 * Run: php tests/ip-bucket.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-lcff-identity.php';

$ok = true;
function t( $name, $cond ) {
	global $ok;
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $name . "\n";
	if ( ! $cond ) {
		$ok = false;
	}
}

t( 'ipv4 as is', LCFF_Identity::ip_bucket( '203.0.113.5' ) === '203.0.113.5' );
t( 'ipv4-mapped', LCFF_Identity::ip_bucket( '::ffff:203.0.113.5' ) === '203.0.113.5' );
t( 'same /64 shares a bucket', LCFF_Identity::ip_bucket( '2001:db8:1:2:aaaa::1' ) === LCFF_Identity::ip_bucket( '2001:db8:1:2:ffff::9' ) );
t( 'different /64 differs', LCFF_Identity::ip_bucket( '2001:db8:1:2::1' ) !== LCFF_Identity::ip_bucket( '2001:db8:1:3::1' ) );

t( 'zone id is ignored', LCFF_Identity::ip_bucket( 'fe80::1%eth0' ) === LCFF_Identity::ip_bucket( 'fe80::1' ) );
t( 'zone id does not return raw', strpos( LCFF_Identity::ip_bucket( 'fe80::1%eth0' ), '%' ) === false );
t( 'loopback ::1', LCFF_Identity::ip_bucket( '::1' ) === '::/64' );
t( 'garbage comes back unchanged', LCFF_Identity::ip_bucket( 'not-an-ip' ) === 'not-an-ip' );
t( 'garbage with a percent sign comes back unchanged', LCFF_Identity::ip_bucket( 'abc%eth0' ) === 'abc%eth0' );
t( 'empty comes back empty', LCFF_Identity::ip_bucket( '' ) === '' );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
