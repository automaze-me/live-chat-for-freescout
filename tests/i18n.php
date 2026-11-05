<?php
/**
 * Translations: the .pot covers every gettext string in the PHP code, both
 * German files translate all of it (de_DE with "du", de_DE_formal with
 * "Sie"), the compiled .mo files match the .po files, every call with a
 * placeholder has a translator comment, the text domain loads on init, and
 * the widget's date locale follows WordPress (LCFF_Widget::js_locale(),
 * LCFF_CONFIG.locale).
 * Run: php tests/i18n.php
 */

define( 'ABSPATH', __DIR__ . '/' );

const LCFF_TEST_DOMAIN = 'live-chat-for-freescout';
$root = dirname( __DIR__ );

// Tiny stand-ins for the WordPress functions the main file and the widget's enqueue() call.
$GLOBALS['lcff_actions']  = array();
$GLOBALS['lcff_td_calls'] = array();
$GLOBALS['lcff_inline']   = array();
$GLOBALS['lcff_locale']   = 'en_US';
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['lcff_actions'][ $hook ][] = $cb; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://shop.example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function load_plugin_textdomain( $domain, $deprecated = false, $rel_path = false ) {
	$GLOBALS['lcff_td_calls'][] = array( $domain, $deprecated, $rel_path );
	return true;
}
function __( $text, $domain = null ) { return $text; }
function is_admin() { return false; }
function get_option( $name, $default = false ) {
	return array(
		'freescout_url' => 'https://help.example.test',
		'site_id'       => str_repeat( 'ab', 16 ),
		'hours'         => array( array( '09:00', '17:00' ), null, null, null, null, null, null ),
	);
}
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url() { return 'https://shop.example.test'; }
function admin_url( $path = '' ) { return 'https://shop.example.test/wp-admin/' . $path; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_add_inline_script( $handle, $js, $position = 'after' ) { $GLOBALS['lcff_inline'][] = $js; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_get_attachment_image_url() { return ''; }
function get_privacy_policy_url() { return ''; }
function wp_timezone_string() { return 'Europe/Berlin'; }
function determine_locale() { return $GLOBALS['lcff_locale']; }

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
function fail( $name ) {
	global $ok;
	$ok = false;
	echo "FAIL $name\n";
}

// Any PHP warning fails the run.
set_error_handler( function ( $no, $msg, $file, $line ) {
	global $ok;
	$ok = false;
	echo "FAIL PHP warning: $msg ($file:$line)\n";
	return true;
} );

/* ---- helpers: PO and MO files ---- */

/**
 * Entries of a .po/.pot file, keyed like a .mo file (msgctxt EOT msgid).
 * Each: msgid, plural, msgstr (list), flags, comments (extracted "#." lines).
 * The header entry has the key ''.
 */
function lcff_po_parse( $file ) {
	$entries = array();
	if ( ! is_readable( $file ) ) {
		return null;
	}
	$blank = array( 'ctxt' => null, 'msgid' => null, 'plural' => null, 'msgstr' => array(), 'flags' => array(), 'comments' => array() );
	$e     = $blank;
	$field = null;
	$store = function ( $e ) use ( &$entries ) {
		if ( null === $e['msgid'] ) {
			return;
		}
		$key             = ( null === $e['ctxt'] ? '' : $e['ctxt'] . "\x04" ) . $e['msgid'];
		$entries[ $key ] = $e;
	};
	$lines = preg_split( '/\r?\n/', (string) file_get_contents( $file ) );
	$lines[] = '';
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			$store( $e );
			$e     = $blank;
			$field = null;
			continue;
		}
		if ( 0 === strpos( $line, '#~' ) ) {
			continue; // obsolete
		}
		if ( 0 === strpos( $line, '#,' ) ) {
			$e['flags'] = array_merge( $e['flags'], array_map( 'trim', explode( ',', substr( $line, 2 ) ) ) );
			continue;
		}
		if ( 0 === strpos( $line, '#.' ) ) {
			$e['comments'][] = trim( substr( $line, 2 ) );
			continue;
		}
		if ( '#' === $line[0] ) {
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$/s', $line, $m ) ) {
			if ( ( 'msgid' === $m[1] || 'msgctxt' === $m[1] ) && null !== $e['msgid'] && null !== $field && 0 === strpos( $field, 'msgstr' ) ) {
				$store( $e ); // a new entry without a blank line before it
				$e = $blank;
			}
			$value = stripcslashes( $m[3] );
			if ( 'msgctxt' === $m[1] ) {
				$e['ctxt'] = $value;
				$field     = 'ctxt';
			} elseif ( 'msgid' === $m[1] ) {
				$e['msgid'] = $value;
				$field      = 'msgid';
			} elseif ( 'msgid_plural' === $m[1] ) {
				$e['plural'] = $value;
				$field       = 'plural';
			} else {
				$n                  = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
				$e['msgstr'][ $n ] = $value;
				$field              = 'msgstr' . $n;
			}
			continue;
		}
		if ( preg_match( '/^"(.*)"$/s', $line, $m ) && null !== $field ) {
			$value = stripcslashes( $m[1] );
			if ( 0 === strpos( $field, 'msgstr' ) ) {
				$e['msgstr'][ (int) substr( $field, 6 ) ] .= $value;
			} else {
				$e[ $field ] .= $value;
			}
			continue;
		}
		echo "FAIL unparsable line in " . basename( $file ) . ": $line\n";
		$GLOBALS['ok'] = false;
	}
	return $entries;
}

/** Header fields of a parsed .po/.pot file. */
function lcff_po_header( $entries ) {
	$out = array();
	if ( ! isset( $entries[''] ) ) {
		return $out;
	}
	foreach ( explode( "\n", (string) $entries['']['msgstr'][0] ) as $row ) {
		$p = strpos( $row, ':' );
		if ( false !== $p ) {
			$out[ trim( substr( $row, 0, $p ) ) ] = trim( substr( $row, $p + 1 ) );
		}
	}
	return $out;
}

/** Original => translation (plural forms joined by NUL), as stored in a .mo file. */
function lcff_mo_parse( $file ) {
	if ( ! is_readable( $file ) ) {
		return null;
	}
	$d = (string) file_get_contents( $file );
	if ( strlen( $d ) < 28 ) {
		return null;
	}
	$magic = unpack( 'V', substr( $d, 0, 4 ) );
	if ( 0x950412de === $magic[1] ) {
		$f = 'V';
	} elseif ( 0xde120495 === $magic[1] ) {
		$f = 'N';
	} else {
		return null;
	}
	$h   = unpack( "{$f}rev/{$f}n/{$f}orig/{$f}trans", substr( $d, 4, 16 ) );
	$out = array();
	for ( $i = 0; $i < $h['n']; $i++ ) {
		$o           = unpack( "{$f}len/{$f}off", substr( $d, $h['orig'] + 8 * $i, 8 ) );
		$t           = unpack( "{$f}len/{$f}off", substr( $d, $h['trans'] + 8 * $i, 8 ) );
		$out[ substr( $d, $o['off'], $o['len'] ) ] = substr( $d, $t['off'], $t['len'] );
	}
	return $out;
}

/** Placeholders of a string, sorted: {name}, %s, %d, %1$s. */
function lcff_placeholders( $s ) {
	preg_match_all( '/\{[a-z]+\}|%(?:\d+\$)?[sd]/', $s, $m );
	$list = $m[0];
	sort( $list );
	return $list;
}

/* ---- helpers: the PHP source scan ---- */

/**
 * Every gettext call in a PHP file: array( 'text', line, file, comment|null ).
 * comment is the nearest comment ending on the call's line or the line above.
 */
function lcff_scan( $file, &$problems ) {
	$single  = array( '__', 'esc_html__', 'esc_attr__', '_e', 'esc_html_e', 'esc_attr_e' );
	$other   = array( '_x', '_ex', 'esc_html_x', 'esc_attr_x', '_n', '_nx', '_n_noop', '_nx_noop' );
	$tokens  = token_get_all( (string) file_get_contents( $file ) );
	$calls   = array();
	$count   = count( $tokens );
	$next    = function ( $i ) use ( $tokens, $count ) {
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( ! is_array( $tokens[ $j ] ) || ! in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $j;
			}
		}
		return $count;
	};
	$literal = function ( $tok ) {
		$q    = $tok[0];
		$body = substr( $tok, 1, -1 );
		if ( "'" === $q ) {
			return strtr( $body, array( "\\\\" => "\\", "\\'" => "'" ) );
		}
		return strtr( $body, array( '\\\\' => '\\', '\\"' => '"', '\\$' => '$', '\\n' => "\n", '\\t' => "\t", '\\r' => "\r" ) );
	};
	for ( $i = 0; $i < $count; $i++ ) {
		$tok = $tokens[ $i ];
		if ( ! is_array( $tok ) || T_STRING !== $tok[0] ) {
			continue;
		}
		$name = $tok[1];
		if ( ! in_array( $name, $single, true ) && ! in_array( $name, $other, true ) ) {
			continue;
		}
		// Skip method calls, static calls and declarations.
		for ( $p = $i - 1; $p >= 0 && is_array( $tokens[ $p ] ) && T_WHITESPACE === $tokens[ $p ][0]; $p-- ) {}
		if ( $p >= 0 && is_array( $tokens[ $p ] ) && in_array( $tokens[ $p ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		$open = $next( $i );
		if ( $open >= $count || '(' !== $tokens[ $open ] ) {
			continue;
		}
		$where = basename( $file ) . ':' . $tok[2];
		if ( in_array( $name, $other, true ) ) {
			$problems[] = "$where: $name() is not covered by this test; extend it";
			continue;
		}
		$arg = $next( $open );
		if ( ! is_array( $tokens[ $arg ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $arg ][0] ) {
			$problems[] = "$where: $name() without a literal string";
			continue;
		}
		$comma  = $next( $arg );
		$domain = $next( $comma );
		if ( ',' !== $tokens[ $comma ] || ! is_array( $tokens[ $domain ] ) || "'" . LCFF_TEST_DOMAIN . "'" !== $tokens[ $domain ][1] ) {
			$problems[] = "$where: $name() without the text domain '" . LCFF_TEST_DOMAIN . "'";
		}
		// The nearest comment before the call that ends on its line or the line above.
		$comment = null;
		for ( $c = $i - 1; $c >= 0; $c-- ) {
			if ( is_array( $tokens[ $c ] ) && in_array( $tokens[ $c ][0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$end = $tokens[ $c ][2] + substr_count( $tokens[ $c ][1], "\n" );
				if ( $end >= $tok[2] - 1 ) {
					$comment = $tokens[ $c ][1];
				}
				break;
			}
			if ( is_array( $tokens[ $c ] ) && $tokens[ $c ][2] < $tok[2] - 1 ) {
				break;
			}
		}
		$calls[] = array( $literal( $tokens[ $arg ][1] ), $tok[2], basename( $file ), $comment );
	}
	return $calls;
}

/* ---- 1. js_locale() and LCFF_CONFIG.locale ---- */

require $root . '/live-chat-for-freescout.php';

t( 'js_locale de_DE_formal', method_exists( 'LCFF_Widget', 'js_locale' ) ? LCFF_Widget::js_locale( 'de_DE_formal' ) : null, 'de-DE' );
t( 'js_locale en_US', method_exists( 'LCFF_Widget', 'js_locale' ) ? LCFF_Widget::js_locale( 'en_US' ) : null, 'en-US' );
t( 'js_locale de', method_exists( 'LCFF_Widget', 'js_locale' ) ? LCFF_Widget::js_locale( 'de' ) : null, 'de' );
t( 'js_locale pt_PT_ao90', method_exists( 'LCFF_Widget', 'js_locale' ) ? LCFF_Widget::js_locale( 'pt_PT_ao90' ) : null, 'pt-PT' );

function lcff_config_for( $locale ) {
	$GLOBALS['lcff_locale'] = $locale;
	$GLOBALS['lcff_inline'] = array();
	$_SERVER['REQUEST_URI'] = '/';
	LCFF_Widget::enqueue();
	$js = isset( $GLOBALS['lcff_inline'][0] ) ? $GLOBALS['lcff_inline'][0] : '';
	if ( 1 !== preg_match( '/^window\.LCFF_CONFIG = (.*);$/s', $js, $m ) ) {
		return null;
	}
	return json_decode( $m[1], true );
}
$cfg = lcff_config_for( 'de_DE_formal' );
t( 'LCFF_CONFIG.locale for de_DE_formal', isset( $cfg['locale'] ) ? $cfg['locale'] : null, 'de-DE' );
$cfg = lcff_config_for( 'en_US' );
t( 'LCFF_CONFIG.locale for en_US', isset( $cfg['locale'] ) ? $cfg['locale'] : null, 'en-US' );

/* ---- 2. the text domain loads on init, from languages/ ---- */

t( 'no textdomain load while the plugin file is included', $GLOBALS['lcff_td_calls'], array() );
$init = isset( $GLOBALS['lcff_actions']['init'] ) ? $GLOBALS['lcff_actions']['init'] : array();
foreach ( $init as $cb ) {
	call_user_func( $cb );
}
t(
	'load_plugin_textdomain on init',
	$GLOBALS['lcff_td_calls'],
	array( array( LCFF_TEST_DOMAIN, false, basename( $root ) . '/languages' ) )
);
foreach ( array( 'plugins_loaded', 'after_setup_theme', 'setup_theme' ) as $early ) {
	$GLOBALS['lcff_td_calls'] = array();
	foreach ( isset( $GLOBALS['lcff_actions'][ $early ] ) ? $GLOBALS['lcff_actions'][ $early ] : array() as $cb ) {
		call_user_func( $cb );
	}
	t( "no textdomain load on $early (before init)", $GLOBALS['lcff_td_calls'], array() );
}
$main = (string) file_get_contents( $root . '/live-chat-for-freescout.php' );
t( 'plugin header names the domain path', 1 === preg_match( '/^\s*\*\s*Domain Path:\s*\/languages\s*$/m', $main ), true );

/* ---- 3. the source scan and translator comments ---- */

$problems = array();
$calls    = array();
foreach ( array_merge( array( $root . '/live-chat-for-freescout.php' ), glob( $root . '/includes/*.php' ) ) as $file ) {
	$calls = array_merge( $calls, lcff_scan( $file, $problems ) );
}
foreach ( $problems as $p ) {
	fail( "scan: $p" );
}
t( 'scan found the gettext calls', count( $calls ) > 80, true );

$comment_problems = array();
foreach ( $calls as $c ) {
	list( $text, $line, $file, $comment ) = $c;
	$ph = array_unique( lcff_placeholders( $text ) );
	if ( ! $ph ) {
		continue;
	}
	$where = "$file:$line \"$text\"";
	if ( null === $comment || false === strpos( $comment, 'translators:' ) ) {
		$comment_problems[] = "$where has placeholders but no translator comment directly above";
		continue;
	}
	foreach ( $ph as $p ) {
		if ( preg_match( '/^%(\d+)\$/', $p, $m ) ) {
			$named = 1 === preg_match( '/\b' . $m[1] . ':/', $comment );
		} else {
			$named = false !== strpos( $comment, $p );
		}
		if ( ! $named ) {
			$comment_problems[] = "$where: the translator comment doesn't explain $p";
		}
	}
	preg_match_all( '/\{[a-z]+\}|%(?:\d+\$)?[sd]/', $comment, $m );
	foreach ( array_unique( $m[0] ) as $p ) {
		if ( ! in_array( $p, $ph, true ) ) {
			$comment_problems[] = "$where: the translator comment names $p, which the string doesn't have";
		}
	}
}
foreach ( $comment_problems as $p ) {
	fail( "translator comment: $p" );
}
t( 'translator comments checked', count( $comment_problems ), 0 );

/* ---- 4. the .pot ---- */

$langs = $root . '/languages';
$pot   = lcff_po_parse( $langs . '/live-chat-for-freescout.pot' );
t( '.pot exists', is_array( $pot ), true );
$pot = is_array( $pot ) ? $pot : array();
$ph  = lcff_po_header( $pot );
t( '.pot Project-Id-Version', isset( $ph['Project-Id-Version'] ) && 0 === strpos( $ph['Project-Id-Version'], 'Live Chat for FreeScout' ), true );
t( '.pot Content-Type', isset( $ph['Content-Type'] ) ? $ph['Content-Type'] : null, 'text/plain; charset=UTF-8' );
t( '.pot Plural-Forms', isset( $ph['Plural-Forms'] ) ? $ph['Plural-Forms'] : null, 'nplurals=2; plural=(n != 1);' );
t( '.pot X-Domain', isset( $ph['X-Domain'] ) ? $ph['X-Domain'] : null, LCFF_TEST_DOMAIN );

$expected_ids = array();
foreach ( $calls as $c ) {
	$expected_ids[ $c[0] ] = true;
}
// The plugin header's translatable fields.
foreach ( array( 'Plugin Name', 'Description' ) as $field ) {
	if ( preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m', $main, $m ) ) {
		$expected_ids[ $m[1] ] = true;
	}
}
$pot_ids = array_diff( array_keys( $pot ), array( '' ) );
$missing = array_diff( array_keys( $expected_ids ), $pot_ids );
$stale   = array_diff( $pot_ids, array_keys( $expected_ids ) );
t( '.pot has every string from the code', array_values( $missing ), array() );
t( '.pot has no string the code no longer uses', array_values( $stale ), array() );
foreach ( $pot_ids as $id ) {
	if ( lcff_placeholders( $id ) && ! preg_grep( '/^translators:/', $pot[ $id ]['comments'] ) ) {
		fail( ".pot: no translator comment for \"$id\"" );
	}
}

/* ---- 5. both German files ---- */

// du: the pronouns, and the imperatives the de_DE file uses, at a sentence start or after "Bitte"
// (so the noun "Versuche" in the middle of a sentence is not one).
$du_words  = '/\b(?:du|dir|dich|dein|deine|deinen|deinem|deiner|deines)\b'
	. '|(?:^|[.!?:]\s+|\bBitte\s+)(?:Chatte|Hinterlasse|Speichere|Kopiere|Korrigiere|Überprüfe|Versuche|Lass|Füge|Tippe|Gib|Warte)\b/iu';
$sie_words = '/\b(?:Sie|Ihnen|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres)\b/u';

// The du pattern also knows the de_DE file's imperatives, which carry no pronoun.
foreach ( array(
	'Chatte mit uns',
	'Wir sind weg. Hinterlasse uns eine Nachricht.',
	'Speichere zuerst die FreeScout-URL.',
	'Kopiere den geheimen Schlüssel erneut.',
	'Korrigiere die Adresse.',
	'Überprüfe sie unter Verwalten → Website-Chat.',
	'Zu viele Tests. Versuche es in einer Minute erneut.',
	'Lass das Feld leer.',
	'Füge ihn erneut aus FreeScout ein.',
	'Nicht gesendet. Tippe, um es erneut zu versuchen.',
	'Bitte gib eine gültige E-Mail-Adresse ein.',
	'Bitte warte einen Moment.',
) as $sample ) {
	t( "du pattern catches \"$sample\"", preg_match( $du_words, $sample ), 1 );
}
foreach ( array(
	'Zu viele Versuche in kurzer Zeit.',
	'Versuchen Sie es in einer Minute erneut.',
	'Lassen Sie das Feld leer.',
	'Bitte warten Sie einen Moment.',
	'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
	'Wartezeit bis zum Versand',
	'Nicht gesendet. Zum erneuten Senden antippen.',
) as $sample ) {
	t( "du pattern leaves \"$sample\" alone", preg_match( $du_words, $sample ), 0 );
}

foreach ( array( 'de_DE', 'de_DE_formal' ) as $locale ) {
	$po = lcff_po_parse( "$langs/live-chat-for-freescout-$locale.po" );
	t( "$locale.po exists", is_array( $po ), true );
	$po = is_array( $po ) ? $po : array();
	$h  = lcff_po_header( $po );
	t( "$locale.po Language", isset( $h['Language'] ) ? $h['Language'] : null, $locale );
	t( "$locale.po Content-Type", isset( $h['Content-Type'] ) ? $h['Content-Type'] : null, 'text/plain; charset=UTF-8' );
	t( "$locale.po Plural-Forms", isset( $h['Plural-Forms'] ) ? $h['Plural-Forms'] : null, 'nplurals=2; plural=(n != 1);' );
	$ids = array_diff( array_keys( $po ), array( '' ) );
	t( "$locale.po has every .pot string", array_values( array_diff( $pot_ids, $ids ) ), array() );
	t( "$locale.po has no string outside the .pot", array_values( array_diff( $ids, $pot_ids ) ), array() );

	$bad = array();
	foreach ( $ids as $id ) {
		$e   = $po[ $id ];
		$str = isset( $e['msgstr'][0] ) ? $e['msgstr'][0] : '';
		if ( '' === trim( $str ) ) {
			$bad[] = "empty: \"$id\"";
			continue;
		}
		if ( in_array( 'fuzzy', $e['flags'], true ) ) {
			$bad[] = "fuzzy: \"$id\"";
		}
		if ( lcff_placeholders( $id ) !== lcff_placeholders( $str ) ) {
			$bad[] = "placeholders differ: \"$id\" → \"$str\"";
		}
		if ( false !== strpos( $str, '"' ) ) {
			$bad[] = "straight double quote (use „…“): \"$str\"";
		}
		if ( preg_match( '/(?<!E-)\b[Ee]mail\b/u', $str ) ) {
			$bad[] = "write E-Mail: \"$str\"";
		}
		if ( 'de_DE' === $locale && preg_match( $sie_words, $str ) ) {
			$bad[] = "formal address in the du file: \"$str\"";
		}
		if ( 'de_DE_formal' === $locale && preg_match( $du_words, $str ) ) {
			$bad[] = "informal address in the Sie file: \"$str\"";
		}
	}
	foreach ( $bad as $b ) {
		fail( "$locale.po: $b" );
	}
	t( "$locale.po translations complete and well-formed", count( $bad ), 0 );

	$ask = 'Where should we send the answer?';
	$str = isset( $po[ $ask ]['msgstr'][0] ) ? $po[ $ask ]['msgstr'][0] : '';
	if ( 'de_DE' === $locale ) {
		t( 'de_DE askAway says du', 1 === preg_match( $du_words, $str ) && 0 === preg_match( $sie_words, $str ), true );
	} else {
		t( 'de_DE_formal askAway says Sie', 1 === preg_match( $sie_words, $str ) && 0 === preg_match( $du_words, $str ), true );
	}

	// The compiled file is what WordPress loads; it must match the .po.
	$mo = lcff_mo_parse( "$langs/live-chat-for-freescout-$locale.mo" );
	t( "$locale.mo exists and parses", is_array( $mo ), true );
	$mo        = is_array( $mo ) ? $mo : array();
	$mismatch  = array();
	foreach ( $ids as $id ) {
		$want = implode( "\0", $po[ $id ]['msgstr'] );
		if ( ! isset( $mo[ $id ] ) || $mo[ $id ] !== $want ) {
			$mismatch[] = $id;
		}
	}
	t( "$locale.mo matches the .po (recompile with msgfmt)", $mismatch, array() );
	t( "$locale.mo has no extra strings", count( array_diff( array_keys( $mo ), $ids, array( '' ) ) ), 0 );
	t( "$locale.mo askAway is the .po's", isset( $mo[ $ask ] ) ? $mo[ $ask ] : null, $str );
}

/* ---- 6. Austrian and Swiss German, generated from the two sources (Ruling P4) ---- */

$generated = array(
	// locale => array( source, ß becomes ss, form of address )
	'de_AT'          => array( 'de_DE', false, 'du' ),
	'de_CH'          => array( 'de_DE_formal', true, 'Sie' ),
	'de_CH_informal' => array( 'de_DE', true, 'du' ),
);
foreach ( $generated as $locale => $def ) {
	list( $source, $ss, $form ) = $def;
	$file = "$langs/live-chat-for-freescout-$locale.po";
	$po   = lcff_po_parse( $file );
	t( "$locale.po exists", is_array( $po ), true );
	$po  = is_array( $po ) ? $po : array();
	$src = lcff_po_parse( "$langs/live-chat-for-freescout-$source.po" );
	$src = is_array( $src ) ? $src : array();
	$raw = (string) @file_get_contents( $file );
	t(
		"$locale.po starts with the generator comment",
		0 === strpos( $raw, "# Generated from live-chat-for-freescout-$source.po by tools/derive-locales.php. Do not edit.\n" ),
		true
	);
	$h = lcff_po_header( $po );
	t( "$locale.po Language", isset( $h['Language'] ) ? $h['Language'] : null, $locale );
	$src_h = lcff_po_header( $src );
	unset( $h['Language'], $src_h['Language'] );
	t( "$locale.po keeps the other headers", $h, $src_h );
	t( "$locale.po msgids equal $source's, in order", array_keys( $po ), array_keys( $src ) );
	$diff = array();
	foreach ( $src as $id => $e ) {
		if ( '' === $id || ! isset( $po[ $id ] ) ) {
			continue;
		}
		$want = $e['msgstr'];
		if ( $ss ) {
			$want = str_replace( array( 'ß', 'ẞ' ), array( 'ss', 'SS' ), $want );
		}
		if ( $po[ $id ]['msgstr'] !== $want ) {
			$diff[] = $id;
		}
	}
	t( "$locale.po msgstr equals $source's after the transform", $diff, array() );
	if ( $ss ) {
		t( "$locale.po has no ß", false !== strpos( $raw, 'ß' ) || false !== strpos( $raw, 'ẞ' ), false );
	}
	$bad = array();
	foreach ( $po as $id => $e ) {
		if ( '' === $id ) {
			continue;
		}
		$str = $e['msgstr'][0];
		if ( 'du' === $form && preg_match( $sie_words, $str ) ) {
			$bad[] = "formal address in a du file: \"$str\"";
		}
		if ( 'Sie' === $form && preg_match( $du_words, $str ) ) {
			$bad[] = "informal address in a Sie file: \"$str\"";
		}
	}
	t( "$locale.po keeps the $form form", $bad, array() );
	$ask = 'Where should we send the answer?';
	$str = isset( $po[ $ask ]['msgstr'][0] ) ? $po[ $ask ]['msgstr'][0] : '';
	if ( 'du' === $form ) {
		t( "$locale askAway says du", 1 === preg_match( $du_words, $str ) && 0 === preg_match( $sie_words, $str ), true );
	} else {
		t( "$locale askAway says Sie", 1 === preg_match( $sie_words, $str ) && 0 === preg_match( $du_words, $str ), true );
	}

	$mo = lcff_mo_parse( "$langs/live-chat-for-freescout-$locale.mo" );
	t( "$locale.mo exists and parses", is_array( $mo ), true );
	$mo       = is_array( $mo ) ? $mo : array();
	$mismatch = array();
	foreach ( $po as $id => $e ) {
		if ( '' === $id ) {
			continue;
		}
		if ( ! isset( $mo[ $id ] ) || $mo[ $id ] !== implode( "\0", $e['msgstr'] ) ) {
			$mismatch[] = $id;
		}
	}
	t( "$locale.mo matches the .po (recompile with msgfmt)", $mismatch, array() );
	t( "$locale.mo has no extra strings", count( array_diff( array_keys( $mo ), array_keys( $po ), array( '' ) ) ), 0 );
}
t( 'js_locale de_AT', LCFF_Widget::js_locale( 'de_AT' ), 'de-AT' );
t( 'js_locale de_CH', LCFF_Widget::js_locale( 'de_CH' ), 'de-CH' );
t( 'js_locale de_CH_informal', LCFF_Widget::js_locale( 'de_CH_informal' ), 'de-CH' );

echo $ok ? "PASS\n" : "FAIL\n";
exit( $ok ? 0 : 1 );
