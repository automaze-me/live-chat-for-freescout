<?php
/**
 * Prints update.json for a release tag. The release workflow attaches it to
 * every GitHub release; installed copies read it through
 * releases/latest/download/update.json (includes/class-lcff-updater.php).
 *
 *   php tools/update-manifest.php v0.1.0 > update.json
 *
 * Fails (exit 1, nothing on stdout) unless the tag is "v" plus the Version
 * header, and the Version header, LCFF_VERSION and readme.txt's Stable tag
 * agree.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root   = dirname( __DIR__ );
$main   = (string) file_get_contents( $root . '/live-chat-for-freescout.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );
$tag    = isset( $argv[1] ) ? (string) $argv[1] : '';

function lcff_fail( $msg ) {
	fwrite( STDERR, "update-manifest: $msg\n" );
	exit( 1 );
}
function lcff_header( $text, $field ) {
	return preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m', $text, $m ) ? $m[1] : '';
}
function lcff_readme( $text, $field ) {
	return preg_match( '/^' . preg_quote( $field, '/' ) . ':[ \t]*(.*?)[ \t]*$/m', $text, $m ) ? $m[1] : '';
}

$version  = lcff_header( $main, 'Version' );
$constant = preg_match( "/define\( 'LCFF_VERSION', '([^']+)' \)/", $main, $m ) ? $m[1] : '';
$stable   = lcff_readme( $readme, 'Stable tag' );

if ( 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
	lcff_fail( "the Version header \"$version\" is not x.y.z" );
}
if ( $constant !== $version || $stable !== $version ) {
	lcff_fail( "Version $version, LCFF_VERSION $constant and Stable tag $stable must agree" );
}
if ( 'v' . $version !== $tag ) {
	lcff_fail( "the tag \"$tag\" is not v$version" );
}

$manifest = array(
	'version'      => $version,
	'requires'     => lcff_header( $main, 'Requires at least' ),
	'requires_php' => lcff_header( $main, 'Requires PHP' ),
	'tested'       => lcff_readme( $readme, 'Tested up to' ),
);
foreach ( $manifest as $key => $value ) {
	if ( 1 !== preg_match( '/^\d+(?:\.\d+){0,2}$/', $value ) ) {
		lcff_fail( "$key \"$value\" is not a version number" );
	}
}
echo json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
