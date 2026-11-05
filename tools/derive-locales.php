<?php
/**
 * Derives the Austrian and Swiss German translation files from the two
 * hand-edited German sources. Run from the plugin root: php tools/derive-locales.php
 *
 *   de_AT          <- de_DE         unchanged (du)
 *   de_CH          <- de_DE_formal  with ß -> ss and ẞ -> SS (Sie)
 *   de_CH_informal <- de_DE         with ß -> ss (du)
 *
 * Only msgstr text and the Language header change; msgids, the other headers
 * and the order stay. Compile the .mo files afterwards with msgfmt.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root   = dirname( __DIR__ );
$dir    = $root . '/languages';
$prefix = 'live-chat-for-freescout-';

$targets = array(
	'de_AT'          => array( 'de_DE', false ),
	'de_CH'          => array( 'de_DE_formal', true ),
	'de_CH_informal' => array( 'de_DE', true ),
);

foreach ( $targets as $locale => $def ) {
	list( $source, $ss ) = $def;
	$src_file = "$dir/$prefix$source.po";
	if ( ! is_readable( $src_file ) ) {
		fwrite( STDERR, "Missing source: $src_file\n" );
		exit( 1 );
	}
	$lines = preg_split( '/\r?\n/', rtrim( (string) file_get_contents( $src_file ), "\r\n" ) );
	$out   = array( "# Generated from $prefix$source.po by tools/derive-locales.php. Do not edit." );
	$in_str = false;
	foreach ( $lines as $line ) {
		if ( 0 === strpos( $line, 'msgstr' ) ) {
			$in_str = true;
		} elseif ( '' === $line || 0 === strpos( $line, 'msgid' ) || 0 === strpos( $line, 'msgctxt' ) || '#' === $line[0] ) {
			$in_str = false;
		}
		if ( $in_str && $ss ) {
			$line = str_replace( array( 'ß', 'ẞ' ), array( 'ss', 'SS' ), $line );
		}
		if ( 0 === strpos( $line, '"Language: ' ) ) {
			$line = '"Language: ' . $locale . '\n"';
		}
		$out[] = $line;
	}
	file_put_contents( "$dir/$prefix$locale.po", implode( "\n", $out ) . "\n" );
	echo "Wrote languages/$prefix$locale.po\n";
}
