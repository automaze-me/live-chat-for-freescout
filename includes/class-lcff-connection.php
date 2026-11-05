<?php
/**
 * "Test connection" on the settings page: asks FreeScout whether the Site ID,
 * the secret and this website's address match. Tests the saved settings only.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The settings page's "Test connection" request and its messages.
 */
class LCFF_Connection {

	const ACTION    = 'lcff_test_connection';
	const NONCE     = 'lcff_test';
	const PING_TTL  = 300;
	const TIMEOUT   = 10;
	const BIND_ZERO = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * Registers the admin-ajax handler (logged-in users only).
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_ajax' ) );
	}

	/**
	 * Runs the test for an administrator with a valid nonce; answers JSON.
	 * A failed check is a 403 with data.message, which the admin script shows.
	 */
	public static function handle_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '' ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Reload the page and try again.', 'live-chat-for-freescout' ) ), 403 );
		}
		nocache_headers();
		wp_send_json( self::test() );
	}

	/**
	 * Origin (scheme://host[:port], lowercase, default port dropped) of home_url().
	 *
	 * @return string
	 */
	public static function home_origin() {
		$origin = LCFF_Settings::origin( home_url() );
		return null === $origin ? '' : $origin;
	}

	/**
	 * Pings FreeScout with the saved settings.
	 *
	 * @return array array( 'ok' => bool, 'message' => string ).
	 */
	public static function test() {
		if ( ! LCFF_Settings::is_configured() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Save the FreeScout URL and Site ID first.', 'live-chat-for-freescout' ),
			);
		}

		$s    = LCFF_Settings::get();
		$body = array();

		if ( LCFF_Settings::identity_enabled() ) {
			$iat     = time();
			$payload = array(
				'typ'    => 'ping',
				'sid'    => $s['site_id'],
				'bind'   => self::BIND_ZERO,
				'iat'    => $iat,
				'exp'    => $iat + self::PING_TTL,
				'jti'    => bin2hex( random_bytes( 16 ) ),
				'origin' => self::home_origin(),
			);
			$token   = LCFF_Crypto::seal( $payload, $s['secret'], $s['site_id'] );
			if ( null !== $token ) {
				$body['token'] = $token;
			}
		}

		// wp_remote_post(), not wp_safe_remote_post(): the safe variant refuses loopback and private-network
		// hosts, which would break FreeScout on a LAN or the dev stack. The URL is admin-saved, and this
		// request needs manage_options and a nonce.
		$response = wp_remote_post(
			$s['freescout_url'] . '/wordpress-chat/api/v1/sites/' . $s['site_id'] . '/ping',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( (object) $body ),
			)
		);

		$no_gcm = LCFF_Settings::is_valid_secret( $s['secret'] ) && ! LCFF_Crypto::available();
		return self::interpret( $response, self::home_origin(), $no_gcm );
	}

	/**
	 * Maps an answer of wp_remote_post() (or a WP_Error) to the admin message.
	 * Pure.
	 *
	 * @param array|WP_Error $response    The result of wp_remote_post().
	 * @param string         $home_origin This website's origin, for the mismatch message.
	 * @param bool           $no_gcm      A secret is saved but this PHP can't seal tokens, so no token was sent.
	 * @return array array( 'ok' => bool, 'message' => string ).
	 */
	public static function interpret( $response, $home_origin = '', $no_gcm = false ) {
		if ( is_wp_error( $response ) ) {
			/* translators: %s: the error from the HTTP request */
			return self::result( false, sprintf( __( "Couldn't reach FreeScout: %s", 'live-chat-for-freescout' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$err  = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : '';

		if ( 200 === $code && is_array( $data ) && isset( $data['ok'] ) && true === $data['ok'] && isset( $data['secret'] ) ) {
			if ( 'invalid' === $data['secret'] ) {
				return self::result( false, __( "FreeScout knows this Site ID, but the secret doesn't match. Copy the secret again from FreeScout.", 'live-chat-for-freescout' ) );
			}
			if ( 'ok' === $data['secret'] ) {
				if ( isset( $data['origin'] ) && 'mismatch' === $data['origin'] ) {
					$expected = isset( $data['expected_origin'] ) && is_string( $data['expected_origin'] ) ? $data['expected_origin'] : '';
					/* translators: 1: the website address FreeScout expects, 2: this website's address */
					return self::result( false, sprintf( __( "FreeScout expects this website at %1\$s, but this site's address is %2\$s. Correct the address in FreeScout under Manage → Website Chat.", 'live-chat-for-freescout' ), $expected, $home_origin ) );
				}
				return self::result( true, __( 'Connected. FreeScout accepted the Site ID and the secret.', 'live-chat-for-freescout' ) );
			}
			if ( 'missing' === $data['secret'] ) {
				if ( $no_gcm ) {
					return self::result( false, __( "This server's PHP can't encrypt with AES-256-GCM (OpenSSL), so logged-in visitors and order numbers can't be recognized.", 'live-chat-for-freescout' ) );
				}
				return self::result( true, __( "Connected. No secret is saved, so logged-in visitors and order numbers won't be recognized.", 'live-chat-for-freescout' ) );
			}
		} elseif ( 404 === $code && 'not_found' === $err ) {
			return self::result( false, __( "FreeScout doesn't know this Site ID. Check it under Manage → Website Chat.", 'live-chat-for-freescout' ) );
		} elseif ( 503 === $code && 'maintenance' === $err ) {
			return self::result( false, __( 'FreeScout needs a database update first (System → Tools → Migrate DB).', 'live-chat-for-freescout' ) );
		} elseif ( 429 === $code ) {
			return self::result( false, __( 'Too many tests. Try again in a minute.', 'live-chat-for-freescout' ) );
		}

		/* translators: %d: the HTTP status code */
		return self::result( false, sprintf( __( 'FreeScout answered, but not as expected (HTTP %d). Is the Website Chat module installed and active?', 'live-chat-for-freescout' ), $code ) );
	}

	/**
	 * Builds the answer array.
	 *
	 * @param bool   $ok      Success.
	 * @param string $message Admin message.
	 * @return array
	 */
	private static function result( $ok, $message ) {
		return array(
			'ok'      => $ok,
			'message' => $message,
		);
	}
}
