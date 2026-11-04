<?php
/**
 * Shared test vectors for LCFF_Crypto::seal(). Run: php tests/crypto-vector.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-lcff-crypto.php';

$secret = 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE';
$sid    = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
$bind   = hash( 'sha256', 'vector-chat-token' );
$iv     = str_repeat( "\x02", 12 );

$login = array(
	'typ'   => 'login',
	'sid'   => $sid,
	'bind'  => $bind,
	'iat'   => 1700000000,
	'exp'   => 1700000300,
	'jti'   => '0123456789abcdef0123456789abcdef',
	'uid'   => 42,
	'email' => 'anna@example.com',
	'name'  => 'Anna Example',
);
$order = array(
	'typ'   => 'order',
	'sid'   => $sid,
	'bind'  => $bind,
	'iat'   => 1700000000,
	'exp'   => 1700000300,
	'jti'   => 'fedcba9876543210fedcba9876543210',
	'num'   => '10482',
	'found' => true,
	'email' => 'anna@example.com',
	'name'  => 'Anna Example',
);

$expected_login = 'v1.AgICAgICAgICAgICn4h2GSQ3ZLA1PY6Ae_V96Bht-3mCt65FqGycLeYC76imKEobLAUArT4B9wHHyzNAhDtH05n1hZW3qG86Tx63pED6fodzwatMuTWIxW3uO0Ek_UGqSjTnA6eVm9NPsz6Mi-2T2J3XdjlpmocSzH1kV1CPbF2PEeyNqF54Jb03SMUcypFXu1m5O6iIL4i0ON4CnedXQBlfbMODjxO7l6ZZ4g6YDISRPDg-JPvwjLBk409CIt09JEftVvnWlPgp75qUJlJMWiLQgTV2PbRa6OL9U0wqBwoysQmhgeEk5NLJcMehWFR8YW3ozJ7VWKxchdcVWNCUtZmH64gSx9DlXh6IQiFPTOpj6TiayAQh6gvCFMp4ZZmGh0Lk64JlJgYQQDxGcJrN0ydc_14W-BPhGTUqLuJCg1R8VLGxuRpgW2JKdAfQEE2daE6oQO1uAK07R56Gl3exXiifpHI0jz5jnyLXd7T9_VirbwODdf51IVgUO4JC1j_ilgHqDOR_O51d-j04tShk9XjAfUyje7Q4bmK3E-iBRoHpw06lWnEO0qs15P_u6K_4yecR8jJkADZ36HOHPRmO52vR7YGUXjk34uUj4l9ane3KiojrDnOx_-gmMPB3Czlph-p1MUyclT2L9fIJrBiQz2VT4oRKz6ezR-Ld3FybGAsg9ZO51SUR2XzbumxZBvdY3QB0cWrmlD2MwGAs';
$expected_order = 'v1.AgICAgICAgICAgICn4h2GSQ3ZLA2II2MZ_V96Bht-3mCt65FqGycLeYC76imKEobLAUArT4B9wHHyzNAhDtH05n1hZW3qG86Tx63pED6fodzwatMuTWIxW3uO0Ek_UGqSjTnA6eVm9NPsz6Mi-2T2J3XdjlpmocSzH1kV1CPbF2PEeyNqF54Jb03SMUcypFXu1m5O6iIL4i0ON4CnedXQBlfbMODjxO7l6ZZ4g6YDISRPDg-JPvw2uQysxkWLdIyKxO7Bq-Cwq59ucrCcl1DVS2E12UgaeJa6OLmT0UqBxwxrR_83qJhqpacJ8irFA9IdmDsjczcUONWhpgNVpOUupWirMpR687nWhCuVS0AEKRoqijfyh4ji0WMVcodPdjL1w6h6d9lJgYQQDxGcJrN0ydc_14W-BPhGTUqLuJCg1R8VLGxuRpgW2JKdAfQEE2daE6oQO1uAK07R56Gl3exXiifpHI0jz5jnyLXd7T9_VirbwODdf51IVgUO4JC1j_ilgHqDOR_O51d-j04tShk9XjAfUyje7Q4bmK3E-iBRoHpw06lWnEO0qs15P_u6K_4yecR8jJkADZ36HOHPRmO52vR7YGUXjk34uUj4l9ane3KiojrDnOx_-gmMPB3Czlph-p1MUyclT2L9fIJrBiQz2VT4oRKz6ezR-Ld3FybGAsg9ZO51SUR2XzbumxhE2HcGe04VsyyVwKGToml';

$ok = true;
foreach ( array(
	'login' => array( $login, $expected_login ),
	'order' => array( $order, $expected_order ),
) as $name => $case ) {
	$got = LCFF_Crypto::seal( $case[0], $secret, $sid, $iv );
	if ( $got === $case[1] ) {
		echo "ok   $name\n";
	} else {
		$ok = false;
		echo "FAIL $name\n  got:      $got\n  expected: {$case[1]}\n";
	}
}

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
