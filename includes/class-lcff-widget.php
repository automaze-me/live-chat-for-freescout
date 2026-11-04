<?php
/**
 * Front-end widget loader.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCFF_Widget {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( is_admin() || ! LCFF_Settings::is_configured() ) {
			return;
		}

		$s = LCFF_Settings::get();

		// Decided per request path on the server, so a full-page cache (which keys on the URL) stays correct.
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
		if ( '' === $request_path ) {
			$request_path = '/';
		}
		if ( LCFF_Appearance::is_hidden( rawurldecode( $request_path ), $s['hidden_paths'] ) ) {
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
			'identity'   => $identity,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'woo'        => $identity && function_exists( 'wc_get_order' ),
			'strings'  => array(
				'title'       => __( 'Chat with us', 'live-chat-for-freescout' ),
				'placeholder' => __( 'Type a message…', 'live-chat-for-freescout' ),
				'send'        => __( 'Send', 'live-chat-for-freescout' ),
				'close'       => __( 'Close chat', 'live-chat-for-freescout' ),
				'retry'       => __( "Couldn't send. Tap to retry.", 'live-chat-for-freescout' ),
				'unsent'      => __( "This message couldn't be sent.", 'live-chat-for-freescout' ),
				'askIntro'        => __( "We usually reply within a few minutes. If you leave the page, we'll send the answer by email.", 'live-chat-for-freescout' ),
				'askLabel'        => __( 'Email or order no.', 'live-chat-for-freescout' ),
				'askLabelEmail'   => __( 'Email', 'live-chat-for-freescout' ),
				'askOk'           => __( 'OK', 'live-chat-for-freescout' ),
				'askLater'        => __( 'Not now', 'live-chat-for-freescout' ),
				'askThanks'       => __( 'Thanks!', 'live-chat-for-freescout' ),
				'askEmailOnly'    => __( 'Could you leave your email address? Then we can send you the answer.', 'live-chat-for-freescout' ),
				'askInvalid'      => __( 'Please enter a valid email address.', 'live-chat-for-freescout' ),
				'privacy'         => __( 'Privacy policy', 'live-chat-for-freescout' ),
				'noticeEmail'     => __( 'This conversation continues by email. The link in our email brings you back here.', 'live-chat-for-freescout' ),
				'noticeElsewhere' => __( 'This conversation now continues in another browser.', 'live-chat-for-freescout' ),
				'continuePrompt'  => __( 'Continue your conversation here?', 'live-chat-for-freescout' ),
				'continueButton'  => __( 'Continue chat', 'live-chat-for-freescout' ),
				'continueFailed'  => __( 'This link has expired or was already used.', 'live-chat-for-freescout' ),
				'viaEmail'        => __( 'via email', 'live-chat-for-freescout' ),
			),
			)
		);

		wp_add_inline_script( 'lcff-loader', 'window.LCFF_CONFIG = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
