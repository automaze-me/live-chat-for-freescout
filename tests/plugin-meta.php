<?php
/**
 * Plugin header and readme.txt agree and meet wordpress.org's rules.
 * Run: php tests/plugin-meta.php
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

$main   = (string) file_get_contents( $root . '/live-chat-for-freescout.php' );
$readme = (string) file_get_contents( $root . '/readme.txt' );

function lcff_header_field( $text, $field ) {
	return preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m', $text, $m ) ? $m[1] : null;
}
function lcff_readme_field( $text, $field ) {
	return preg_match( '/^' . preg_quote( $field, '/' ) . ':[ \t]*(.*?)[ \t]*$/m', $text, $m ) ? $m[1] : null;
}

preg_match( "/define\( 'LCFF_VERSION', '([^']+)' \)/", $main, $m );
$version = isset( $m[1] ) ? $m[1] : null;

t( 'plugin Requires at least', lcff_header_field( $main, 'Requires at least' ), '6.3' );
t( 'readme Requires at least', lcff_readme_field( $readme, 'Requires at least' ), '6.3' );
t( 'Requires PHP matches', lcff_readme_field( $readme, 'Requires PHP' ), lcff_header_field( $main, 'Requires PHP' ) );
t( 'Requires PHP is 7.4', lcff_header_field( $main, 'Requires PHP' ), '7.4' );
t( 'Stable tag equals LCFF_VERSION', lcff_readme_field( $readme, 'Stable tag' ), $version );
t( 'Version equals LCFF_VERSION', lcff_header_field( $main, 'Version' ), $version );
t( 'version is 0.1.0', $version, '0.1.0' );
t( 'Contributors', lcff_readme_field( $readme, 'Contributors' ), 'automazeme' );
t( 'Tested up to', lcff_readme_field( $readme, 'Tested up to' ), '7.1' );
t( 'Author', lcff_header_field( $main, 'Author' ), 'automaze.me' );
t( 'Author URI', lcff_header_field( $main, 'Author URI' ), 'https://automaze.me' );
t( 'License matches', lcff_readme_field( $readme, 'License' ), lcff_header_field( $main, 'License' ) );

foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'Changelog' ) as $section ) {
	t( "readme has == $section ==", 1 === preg_match( '/^== ' . preg_quote( $section, '/' ) . ' ==\s*$/m', $readme ), true );
}
$tags = array_filter( array_map( 'trim', explode( ',', (string) lcff_readme_field( $readme, 'Tags' ) ) ) );
t( 'between 1 and 5 tags', count( $tags ) >= 1 && count( $tags ) <= 5, true );

// The short description is the first paragraph after the header block.
$short = '';
if ( preg_match( '/^=== .+ ===\n(?:[A-Za-z ]+:.*\n)+\n(.+?)\n\n/s', $readme, $m ) ) {
	$short = trim( $m[1] );
}
t( 'short description exists', '' !== $short, true );
t( 'short description is at most 150 characters', preg_match_all( '/./su', $short ) <= 150, true );
t( 'readme names the module repo', false !== strpos( $readme, 'https://github.com/automaze-me/freescout-wordpresschat' ), true );
t( 'readme points to the Privacy section', false !== strpos( $readme, 'Privacy' ), true );
t( 'no RhinoBag reference', preg_match( '/rhinobag/i', $readme . $main ), 0 );

$dist = (string) @file_get_contents( $root . '/.distignore' );
$want = array( '.git', '.github', '.gitattributes', '.gitignore', '.distignore', 'tests', 'tools', 'node_modules', '*.zip', '.DS_Store' );
t( '.distignore lines', array_values( array_filter( array_map( 'trim', explode( "\n", $dist ) ) ) ), $want );
$attr = (string) file_get_contents( $root . '/.gitattributes' );
t( '.gitattributes ignores .distignore', 1 === preg_match( '#^(/)?\.distignore export-ignore$#m', $attr ), true );
t( '.gitattributes ignores /tools', 1 === preg_match( '#^/tools export-ignore$#m', $attr ), true );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
