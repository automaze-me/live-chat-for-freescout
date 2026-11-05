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

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
