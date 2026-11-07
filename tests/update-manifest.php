<?php
/**
 * tools/update-manifest.php, which the release workflow runs on a tag: it
 * prints update.json from the plugin header and readme.txt, and fails when
 * the tag, the Version header, LCFF_VERSION and the Stable tag disagree.
 * Run: php tests/update-manifest.php
 */

$root = dirname( __DIR__ );
$ok   = true;
function t( $name, $got, $expected ) {
	global $ok;
	if ( $got === $expected ) {
		echo "ok   $name\n";
	} else {
		$ok = false;
		echo "FAIL $name: got " . var_export( $got, true ) . ', expected ' . var_export( $expected, true ) . "\n";
	}
}
function lcff_tool( $root, $tag ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/tools/update-manifest.php' ) . ' ' . escapeshellarg( $tag ) . ' 2>/dev/null';
	exec( $cmd, $out, $code );
	return array( $code, implode( "\n", $out ) );
}

$main = (string) file_get_contents( $root . '/live-chat-for-freescout.php' );
preg_match( "/define\( 'LCFF_VERSION', '([^']+)' \)/", $main, $m );
$version = $m[1];

list( $code, $json ) = lcff_tool( $root, 'v' . $version );
t( 'the matching tag succeeds', $code, 0 );
$data = json_decode( $json, true );
t( 'prints update.json', $data, array( 'version' => $version, 'requires' => '6.3', 'requires_php' => '7.4', 'tested' => '7.1' ) );

list( $code, $json ) = lcff_tool( $root, 'v9.9.9' );
t( 'another tag fails', $code, 1 );
t( 'and prints nothing', $json, '' );
list( $code ) = lcff_tool( $root, $version );
t( 'a tag without the v fails', $code, 1 );
list( $code ) = lcff_tool( $root, '' );
t( 'no tag fails', $code, 1 );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
