<?php
/**
 * uninstall.php removes the settings and the rate-limit transients from every
 * site: once on a single site, once per site on a multisite network.
 * Run: php tests/uninstall.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'live-chat-for-freescout/live-chat-for-freescout.php' );

$GLOBALS['log']       = array();
$GLOBALS['multisite'] = false;
$GLOBALS['sites']     = array();
$GLOBALS['current']   = 1;
$GLOBALS['stack']     = array();

function is_multisite() { return $GLOBALS['multisite']; }
function get_sites( $args = array() ) {
	$GLOBALS['log'][] = array( 'get_sites', $args );
	return $GLOBALS['sites'];
}
function switch_to_blog( $id ) {
	$GLOBALS['stack'][] = $GLOBALS['current'];
	$GLOBALS['current'] = $id;
	$GLOBALS['log'][]   = array( 'switch', $id );
}
function restore_current_blog() {
	$GLOBALS['current'] = array_pop( $GLOBALS['stack'] );
	$GLOBALS['log'][]   = array( 'restore', $GLOBALS['current'] );
}
function delete_option( $name ) {
	$GLOBALS['log'][] = array( 'delete_option', $GLOBALS['current'], $name );
	return true;
}

class LCFF_Test_WPDB {
	public $options = 'wp_options';
	public function prepare( $q, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $q ), $args );
	}
	public function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}
	public function query( $q ) {
		$GLOBALS['log'][] = array( 'query', $GLOBALS['current'], $q );
		return 0;
	}
}

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
set_error_handler( function ( $no, $msg, $file, $line ) {
	global $ok;
	$ok = false;
	echo "FAIL PHP warning: $msg ($file:$line)\n";
	return true;
} );

function lcff_run_uninstall() {
	$GLOBALS['log']     = array();
	$GLOBALS['current'] = 1;
	$GLOBALS['stack']   = array();
	$GLOBALS['wpdb']    = new LCFF_Test_WPDB();
	require dirname( __DIR__ ) . '/uninstall.php';
	return $GLOBALS['log'];
}
function lcff_only( $log, $kind ) {
	return array_values( array_filter( $log, function ( $r ) use ( $kind ) { return $r[0] === $kind; } ) );
}
function lcff_query_ok( $q ) {
	// One DELETE on the options table naming both LIKE patterns (underscores escaped or not).
	$plain = str_replace( '\\', '', $q );
	return false !== strpos( $plain, 'DELETE FROM wp_options' )
		&& false !== strpos( $plain, '_transient_lcff_rl_%' )
		&& false !== strpos( $plain, '_transient_timeout_lcff_rl_%' );
}

// Single site.
$log = lcff_run_uninstall();
t( 'single: one delete_option', lcff_only( $log, 'delete_option' ), array( array( 'delete_option', 1, 'lcff_settings' ) ) );
$q = lcff_only( $log, 'query' );
t( 'single: one transient query', count( $q ), 1 );
t( 'single: the query names both LIKE patterns', isset( $q[0] ) && lcff_query_ok( $q[0][2] ), true );
t( 'single: no site switching', array_merge( lcff_only( $log, 'switch' ), lcff_only( $log, 'restore' ), lcff_only( $log, 'get_sites' ) ), array() );

// Three-site network.
$GLOBALS['multisite'] = true;
$GLOBALS['sites']     = array( 1, 2, 3 );
$log                  = lcff_run_uninstall();
t( 'network: get_sites asks for ids', lcff_only( $log, 'get_sites' ), array( array( 'get_sites', array( 'fields' => 'ids' ) ) ) );
t( 'network: three switches', array_column( lcff_only( $log, 'switch' ), 1 ), array( 1, 2, 3 ) );
t( 'network: every switch restored', count( lcff_only( $log, 'restore' ) ), 3 );
t( 'network: back on the original site', $GLOBALS['current'], 1 );
t(
	'network: one delete_option per site, no other option',
	lcff_only( $log, 'delete_option' ),
	array( array( 'delete_option', 1, 'lcff_settings' ), array( 'delete_option', 2, 'lcff_settings' ), array( 'delete_option', 3, 'lcff_settings' ) )
);
$q = lcff_only( $log, 'query' );
t( 'network: one transient query per site', array_column( $q, 1 ), array( 1, 2, 3 ) );
$all = true;
foreach ( $q as $row ) {
	$all = $all && lcff_query_ok( $row[2] );
}
t( 'network: every query names both LIKE patterns', $all, true );
$seq = array();
foreach ( $log as $r ) {
	if ( 'get_sites' !== $r[0] ) {
		$seq[] = $r[0];
	}
}
t(
	'network: switch, delete, query, restore per site',
	$seq,
	array( 'switch', 'delete_option', 'query', 'restore', 'switch', 'delete_option', 'query', 'restore', 'switch', 'delete_option', 'query', 'restore' )
);

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
