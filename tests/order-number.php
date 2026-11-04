<?php
/**
 * Which answers to the order question are looked up as an order number.
 * Run: php tests/order-number.php
 *
 * Only an answer that is an order number as a whole may be looked up. A
 * number picked out of free text ("Bestellung vom 03.11.2024", a phone
 * number) could find a stranger's order and link this chat to them.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-lcff-identity.php';

$cases = array(
	'10482'                     => '10482',
	' #10482 '                  => '10482',
	'WC-1001/A'                 => 'WC-1001/A',
	'Bestellung vom 03.11.2024' => null,
	'+49 170 1234567'           => null,
	'Order 12345'               => null,
	'Nr. 12345.'                => null,
	'my order from last week'   => null,
	''                          => null,
);

$ok = true;
foreach ( $cases as $input => $expected ) {
	$got = LCFF_Identity::lookup_number( (string) $input );
	if ( $got === $expected ) {
		echo 'ok   ' . var_export( (string) $input, true ) . "\n";
	} else {
		$ok = false;
		echo 'FAIL ' . var_export( (string) $input, true ) . ': got ' . var_export( $got, true ) . ', expected ' . var_export( $expected, true ) . "\n";
	}
}

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
