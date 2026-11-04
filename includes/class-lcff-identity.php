<?php
/**
 * Identity and order lookups answered with sealed tokens (admin-ajax.php).
 *
 * No nonce is used on purpose: full-page caches cannot carry a per-visitor
 * nonce. Cross-site requests are rejected instead, and the tokens are
 * encrypted and bound to the requester's chat token.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCFF_Identity {

	const COOKIE        = 'lcff_logout';
	const RATE_LIMIT    = 10;
	const RATE_WINDOW   = 900;
	const TOKEN_TTL     = 300;
	const NAME_MAX_CHAR = 100;

	public static function init() {
		add_action( 'wp_ajax_lcff_identity', array( __CLASS__, 'handle_identity' ) );
		add_action( 'wp_ajax_nopriv_lcff_identity', array( __CLASS__, 'handle_identity' ) );
		add_action( 'wp_ajax_lcff_order', array( __CLASS__, 'handle_order' ) );
		add_action( 'wp_ajax_nopriv_lcff_order', array( __CLASS__, 'handle_order' ) );
		// The signal is never cleared on wp_login: after a logout, core lands
		// on wp-login.php, where the widget doesn't run, and the next person
		// may log in right there. The widget expires the signal itself.
		add_action( 'wp_logout', array( __CLASS__, 'set_logout_cookie' ) );
	}

	public static function set_logout_cookie() {
		self::cookie( '1', time() + 600 );
	}

	private static function cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Shared request checks. Sends a JSON error and exits on failure.
	 *
	 * @return string The validated bind value.
	 */
	private static function guard() {
		nocache_headers();
		// WordPress adds no-store itself only since 6.8.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json( array( 'error' => 'method_not_allowed' ), 405 );
		}

		$site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) ) : '';
		if ( 'cross-site' === $site ) {
			wp_send_json( array( 'error' => 'forbidden' ), 403 );
		}
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) && ! self::origin_matches( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) ) ) {
			wp_send_json( array( 'error' => 'forbidden' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock.
		$bind = isset( $_POST['bind'] ) ? sanitize_text_field( wp_unslash( $_POST['bind'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $bind ) ) {
			wp_send_json( array( 'error' => 'bad_request' ), 400 );
		}

		if ( ! LCFF_Settings::identity_enabled() ) {
			wp_send_json( array( 'error' => 'identity_disabled' ), 404 );
		}

		return $bind;
	}

	/**
	 * Compares scheme, host and port of an Origin header with home_url().
	 */
	private static function origin_matches( $origin ) {
		$o = wp_parse_url( $origin );
		$h = wp_parse_url( home_url() );
		if ( empty( $o['scheme'] ) || empty( $o['host'] ) || empty( $h['scheme'] ) || empty( $h['host'] ) ) {
			return false;
		}
		$port = static function ( $u ) {
			if ( isset( $u['port'] ) ) {
				return (int) $u['port'];
			}
			return 'https' === strtolower( $u['scheme'] ) ? 443 : 80;
		};
		return strtolower( $o['scheme'] ) === strtolower( $h['scheme'] )
			&& strtolower( $o['host'] ) === strtolower( $h['host'] )
			&& $port( $o ) === $port( $h );
	}

	private static function base_payload( $type, $bind ) {
		$s   = LCFF_Settings::get();
		$iat = time();
		return array(
			'typ'  => $type,
			'sid'  => $s['site_id'],
			'bind' => $bind,
			'iat'  => $iat,
			'exp'  => $iat + self::TOKEN_TTL,
			'jti'  => bin2hex( random_bytes( 16 ) ),
		);
	}

	private static function seal_and_send( array $payload, array $extra = array() ) {
		$s     = LCFF_Settings::get();
		$token = LCFF_Crypto::seal( $payload, $s['secret'], $s['site_id'] );
		if ( null === $token && 'order' === $payload['typ'] && $payload['found'] ) {
			// Oversize or invalid UTF-8: answer exactly like "not found".
			$payload['found'] = false;
			$payload['email'] = null;
			$payload['name']  = null;
			$token            = LCFF_Crypto::seal( $payload, $s['secret'], $s['site_id'] );
		}
		if ( null === $token ) {
			wp_send_json( array( 'error' => 'seal_failed' ), 500 );
		}
		wp_send_json( array_merge( $extra, array( 'token' => $token ) ) );
	}

	private static function truncate( $text ) {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::NAME_MAX_CHAR, 'UTF-8' ) : substr( $text, 0, self::NAME_MAX_CHAR );
	}

	public static function handle_identity() {
		$bind = self::guard();

		if ( ! is_user_logged_in() ) {
			wp_send_json( array( 'logged_in' => false ) );
		}

		$user    = wp_get_current_user();
		$payload = self::base_payload( 'login', $bind );

		$payload['uid']   = (int) $user->ID;
		$payload['email'] = (string) $user->user_email;
		$payload['name']  = self::truncate( $user->display_name );

		self::seal_and_send(
			$payload,
			array(
				'logged_in' => true,
				'acct'      => self::account_marker( $user->ID ),
			)
		);
	}

	/**
	 * Opaque per-site account marker: the widget compares it with the one it
	 * stored to notice that another account logged in on this browser. No
	 * personal data, not reversible without the site's auth salt.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string 16 lowercase hex characters.
	 */
	public static function account_marker( $user_id ) {
		return substr( hash_hmac( 'sha256', (string) $user_id, wp_salt( 'auth' ) ), 0, 16 );
	}

	public static function handle_order() {
		$bind = self::guard();

		if ( ! function_exists( 'wc_get_order' ) ) {
			wp_send_json( array( 'error' => 'woocommerce_missing' ), 404 );
		}

		if ( self::rate_limited() ) {
			wp_send_json( array( 'error' => 'rate_limited' ), 429 );
		}

		// Validated as typed (unslashed only): sanitize_text_field() would
		// change it before the strict check below. It goes back to FreeScout
		// only through display_number(), sealed inside the token.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see class docblock; validated below.
		$raw    = isset( $_POST['number'] ) && is_string( $_POST['number'] ) ? wp_unslash( $_POST['number'] ) : '';
		$number = self::lookup_number( $raw );

		$email = null;
		$name  = null;
		$found = false;
		if ( null !== $number ) {
			$order = self::find_order( $number );
			if ( $order ) {
				$billing = (string) $order->get_billing_email();
				if ( '' !== $billing && is_email( $billing ) ) {
					$found  = true;
					$email  = $billing;
					$name   = self::truncate( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
					$number = (string) $order->get_order_number();
				}
			}
		}

		// Never empty: an answer that is no order number still reaches the
		// agent as "Order number … not found".
		$payload          = self::base_payload( 'order', $bind );
		$payload['num']   = self::display_number( $found ? $number : $raw );
		$payload['found'] = $found;
		$payload['email'] = $email;
		$payload['name']  = $name;

		self::seal_and_send( $payload );
	}

	/**
	 * The number to look up: the input itself, trimmed and without a leading
	 * '#', when it is an order number as a whole; else none. A number is
	 * never picked out of free text: "Bestellung vom 03.11.2024" or a phone
	 * number could find a stranger's order and link this chat to them. Such
	 * answers reach the agent as typed ("not found"), who links by hand.
	 *
	 * @param string $raw Raw input, unslashed.
	 * @return string|null
	 */
	public static function lookup_number( $raw ) {
		$n = trim( (string) $raw );
		if ( 0 === strpos( $n, '#' ) ) {
			$n = substr( $n, 1 );
		}
		return 1 === preg_match( '#^[A-Za-z0-9/_-]{1,32}$#', $n ) ? $n : null;
	}

	/**
	 * The order number as the agent sees it: trimmed, without control
	 * characters or ':' (FreeScout's line item substitutes ":number"), at
	 * most 32 characters, and never empty ('?'). Invalid UTF-8 becomes '?'.
	 *
	 * @param string $text Order number or the visitor's input.
	 * @return string
	 */
	public static function display_number( $text ) {
		$text  = wp_check_invalid_utf8( (string) $text );
		$clean = preg_replace( '/\p{Cc}+/u', '', $text );
		$text  = null === $clean ? '' : $clean;
		$text  = trim( str_replace( ':', '', $text ) );
		$text  = trim( mb_substr( $text, 0, 32, 'UTF-8' ) );
		return '' === $text ? '?' : $text;
	}

	/**
	 * Finds an order by its customer-facing number (HPOS and number plugins safe).
	 *
	 * @param string $number Normalized order number.
	 * @return WC_Order|null
	 */
	public static function find_order( $number ) {
		$order = null;
		$id    = apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
		$found = wc_get_order( $id );
		if ( $found instanceof WC_Order && (string) $found->get_order_number() === $number ) {
			$order = $found;
		}
		$order = apply_filters( 'lcff_find_order', $order, $number );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * 10 lookups per IP per 15 minutes. Only a keyed hash of the IP is used.
	 */
	private static function rate_limited() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'lcff_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );

		$now   = time();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['n'], $state['t'] ) || $state['t'] + self::RATE_WINDOW <= $now ) {
			$state = array(
				'n' => 0,
				't' => $now,
			);
		}
		if ( $state['n'] >= self::RATE_LIMIT ) {
			return true;
		}
		++$state['n'];
		set_transient( $key, $state, max( 1, $state['t'] + self::RATE_WINDOW - $now ) );
		return false;
	}
}
