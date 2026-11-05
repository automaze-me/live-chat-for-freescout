<?php
/**
 * Privacy: WP Consent API registration and the suggested privacy policy text.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCFF_Privacy {

	const SERVICE = 'Live Chat for FreeScout';

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register_consent' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_policy' ) );
	}

	/**
	 * Tells the WP Consent API that this plugin handles consent itself and
	 * describes its one cookie. Does nothing without the API.
	 */
	public static function register_consent() {
		add_filter( 'wp_consent_api_registered_' . plugin_basename( LCFF_PLUGIN_FILE ), '__return_true' );
		if ( function_exists( 'wp_add_cookie_info' ) ) {
			// A third-party signature change must never take the site down.
			try {
				wp_add_cookie_info(
					'lcff_logout',
					self::SERVICE,
					'functional',
					__( '10 minutes', 'live-chat-for-freescout' ),
					__( 'Tells the chat to close the conversation in this browser after you log out of this site.', 'live-chat-for-freescout' ),
					''
				);
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				unset( $e );
			}
		}
	}

	/**
	 * The suggested policy text as HTML: two paragraphs and the tutorial note.
	 *
	 * @return string
	 */
	public static function policy_text() {
		$settings = LCFF_Settings::get();
		$host     = wp_parse_url( (string) $settings['freescout_url'], PHP_URL_HOST );
		$host     = is_string( $host ) ? $host : '';

		/* translators: %s: the host name of the FreeScout help desk, e.g. help.example.com. */
		$flow = sprintf( __( 'When you use the chat on this site, your messages and the files you attach go to our help desk at %s. So does an email address or order number you enter in the chat; with an order number, the help desk also receives the order\'s email address and name. While a conversation is open, the chat sends the path of the page you are viewing. If you are logged in, the chat tells the help desk your account\'s user ID, name and email address.', 'live-chat-for-freescout' ), $host );
		$flow = esc_html( $flow );

		$storage = esc_html( __( 'The chat stores nothing in your browser until you send a message. Then it keeps a random chat token and a few small settings in your browser\'s storage (`lcff_token`, `lcff_seen`, `lcff_ask`, `lcff_acct`, `lcff_page`), so the conversation continues on other pages. When you log out of this site, a cookie (`lcff_logout`, 10 minutes) tells the chat to close the conversation in this browser.', 'live-chat-for-freescout' ) );
		$storage = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $storage );

		return '<p class="privacy-policy-tutorial">' . esc_html( __( 'Add how long your help desk keeps chat conversations.', 'live-chat-for-freescout' ) ) . '</p>'
			. '<p>' . $flow . '</p>'
			. '<p>' . $storage . '</p>';
	}

	public static function add_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( self::SERVICE, self::policy_text() );
	}
}
