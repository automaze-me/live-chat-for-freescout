<?php
/**
 * Front-end widget loader.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the chat widget on the front end.
 */
class LCFF_Widget {

	/**
	 * Hooks the front-end loader.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( is_admin() || ! LCFF_Settings::is_configured() ) {
			return;
		}

		$s = LCFF_Settings::get();

		// Decided per request path on the server, so a full-page cache (which keys on the URL) stays correct.
		// sanitize_text_field() would strip %xx octets from the path; the value is only parsed and matched, never output.
		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_path = LCFF_Appearance::request_path( (string) $request_uri, (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( LCFF_Appearance::is_hidden( $request_path, $s['hidden_paths'] ) ) {
			return;
		}

		$api_base = $s['freescout_url'] . '/wordpress-chat/api/v1/sites/' . $s['site_id'];

		wp_enqueue_style( 'lcff-widget', LCFF_PLUGIN_URL . 'assets/css/lcff-widget.css', array(), LCFF_VERSION );
		wp_enqueue_script(
			'lcff-loader',
			LCFF_PLUGIN_URL . 'assets/js/lcff-loader.js',
			array(),
			LCFF_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$identity = LCFF_Settings::identity_enabled();
		$config   = array_merge(
			LCFF_Appearance::config( $s ),
			array(
			'apiBase'    => $api_base,
			'panelUrl'   => LCFF_PLUGIN_URL . 'assets/js/lcff-panel.js?ver=' . LCFF_VERSION,
			'hoursUrl'   => LCFF_PLUGIN_URL . 'assets/js/lcff-hours.js?ver=' . LCFF_VERSION,
			// The schedule only; the browser decides open/closed, so a cached page never freezes the state.
			'hours'      => self::hours_config( $s ),
			'identity'   => $identity,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'woo'        => $identity && function_exists( 'wc_get_order' ),
			// Sites of a subdirectory network share an origin, so a sub-site adds its ID to the storage key names; the main site and a single site keep the plain names.
			'storageSuffix' => is_multisite() && ! is_main_site() ? '_' . get_current_blog_id() : '',
			// Day and time names in the header follow the WordPress locale, not <html lang>. They match the language of these
			// strings only when a translation for that locale is loaded: a locale without one (e.g. fr_FR, de_AT, de_CH) gets
			// English sentences with localized day names.
			'locale'     => self::js_locale( determine_locale() ),
			'strings'  => array(
				'title'       => __( 'Chat with us', 'live-chat-for-freescout' ),
				'placeholder' => __( 'Type a message…', 'live-chat-for-freescout' ),
				'send'        => __( 'Send', 'live-chat-for-freescout' ),
				'close'       => __( 'Close chat', 'live-chat-for-freescout' ),
				'retry'       => __( "Couldn't send. Tap to retry.", 'live-chat-for-freescout' ),
				'unsent'      => __( "This message couldn't be sent.", 'live-chat-for-freescout' ),
				'attach'      => __( 'Attach a file', 'live-chat-for-freescout' ),
				/* translators: {mb} is replaced in the browser with the size limit in megabytes, e.g. 10. Keep {mb} as it is. */
				'fileTooLarge' => __( 'This file is too large (max {mb} MB).', 'live-chat-for-freescout' ),
				'fileTooLargeNoLimit' => __( 'This file is too large.', 'live-chat-for-freescout' ),
				'fileType'    => __( 'Only images (JPEG, PNG, GIF, WebP) and PDF files can be sent.', 'live-chat-for-freescout' ),
				'fileFailed'  => __( "Couldn't send the file.", 'live-chat-for-freescout' ),
				'fileLoadFailed' => __( "Couldn't load the file.", 'live-chat-for-freescout' ),
				'askIntro'        => __( "We usually reply within a few minutes. If you leave the page, we'll send the answer by email.", 'live-chat-for-freescout' ),
				'askLabel'        => __( 'Email or order no.', 'live-chat-for-freescout' ),
				'askLabelEmail'   => __( 'Email', 'live-chat-for-freescout' ),
				'askOk'           => __( 'OK', 'live-chat-for-freescout' ),
				'askLater'        => __( 'Not now', 'live-chat-for-freescout' ),
				'askAway'         => __( 'Where should we send the answer?', 'live-chat-for-freescout' ),
				'askThanks'       => __( 'Thanks!', 'live-chat-for-freescout' ),
				'askEmailOnly'    => __( 'Could you leave your email address? Then we can send you the answer.', 'live-chat-for-freescout' ),
				'askInvalid'      => __( 'Please enter a valid email address.', 'live-chat-for-freescout' ),
				'privacy'         => __( 'Privacy policy', 'live-chat-for-freescout' ),
				'noticeEmail'     => __( 'This conversation continues by email. The link in our email brings you back here.', 'live-chat-for-freescout' ),
				'noticeElsewhere' => __( 'This conversation now continues in another browser.', 'live-chat-for-freescout' ),
				'continuePrompt'  => __( 'Continue your conversation here?', 'live-chat-for-freescout' ),
				'continueButton'  => __( 'Continue chat', 'live-chat-for-freescout' ),
				'continueFailed'  => __( 'This link has expired or was already used.', 'live-chat-for-freescout' ),
				'continueRetry'   => __( "The chat couldn't be opened. Please try again.", 'live-chat-for-freescout' ),
				'online'          => __( 'Online · usually replies in a few minutes', 'live-chat-for-freescout' ),
				/* translators: Header status while the chat is closed. {time} is replaced in the browser with today's opening time in the site language, e.g. 09:00. Keep {time} as it is. */
				'backToday'       => __( "Back today {time} · we'll answer by email", 'live-chat-for-freescout' ),
				/* translators: Header status while the chat is closed. {day} is replaced in the browser with the weekday name in the site language, e.g. Monday; {time} with the opening time, e.g. 09:00. Keep {day} and {time} as they are. */
				'backDay'         => __( "Back {day} {time} · we'll answer by email", 'live-chat-for-freescout' ),
				'rateLimited'     => __( 'Too many messages in a short time. Please wait a moment.', 'live-chat-for-freescout' ),
				/* translators: Shown when the wait is longer than 90 seconds. {min} is replaced in the browser with the number of minutes to wait, e.g. 60 (always 2 or more). Keep {min} as it is. */
				'rateLimitedMin'  => __( 'Too many messages in a short time. Please try again in {min} minutes.', 'live-chat-for-freescout' ),
				/* translators: Added after the reason why a chat message wasn't sent (e.g. too many messages); the visitor taps the message to send it again. */
				'retryHint'       => __( 'Tap to resend.', 'live-chat-for-freescout' ),
				'viaEmail'        => __( 'via email', 'live-chat-for-freescout' ),
				// The loader's: the bubble's name after the chat failed to load, and its unread count.
				'loadFailed'      => __( "The chat couldn't be loaded. Tap to try again.", 'live-chat-for-freescout' ),
				/* translators: Added to the chat button's name for screen readers, after the team name. {n} is replaced in the browser with the number of unread replies, e.g. 3. Keep {n} as it is. */
				'unreadCount'     => __( '{n} new', 'live-chat-for-freescout' ),
			),
			)
		);

		// The admin texts are decoded: no raw < > & may reach the inline script ("<!--<script" would keep it open).
		wp_add_inline_script( 'lcff-loader', 'window.LCFF_CONFIG = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
	}

	/**
	 * A WordPress locale as a language tag for Intl in the browser: the
	 * first two parts, joined with "-" (de_DE_formal → de-DE, en_US → en-US,
	 * de → de).
	 *
	 * @param string $wp_locale WordPress locale.
	 * @return string
	 */
	public static function js_locale( $wp_locale ) {
		return implode( '-', array_slice( explode( '_', (string) $wp_locale ), 0, 2 ) );
	}

	/**
	 * The working-hours part of LCFF_CONFIG, or null when no day is open.
	 *
	 * @param array $s Stored settings.
	 * @return array|null
	 */
	public static function hours_config( array $s ) {
		$days = LCFF_Settings::sanitize_hours( isset( $s['hours'] ) && is_array( $s['hours'] ) ? $s['hours'] : array() );
		if ( array_filter( $days ) === array() ) {
			return null;
		}
		$tz     = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
		$offset = null;
		if ( 1 === preg_match( '/^([+-])(\d{1,2}):(\d{2})$/', $tz, $m ) ) {
			$offset = ( '-' === $m[1] ? -1 : 1 ) * ( (int) $m[2] * 60 + (int) $m[3] );
			$tz     = null;
		} elseif ( '' === $tz ) {
			$tz = 'UTC';
		}
		return array(
			'tz'     => $tz,
			'offset' => $offset,
			'days'   => $days,
		);
	}
}
