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

		$s        = LCFF_Settings::get();
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

		$config = array(
			'apiBase'  => $api_base,
			'panelUrl' => LCFF_PLUGIN_URL . 'assets/js/lcff-panel.js?ver=' . LCFF_VERSION,
			'strings'  => array(
				'title'       => __( 'Chat with us', 'live-chat-for-freescout' ),
				'welcome'     => __( 'Hi! How can we help?', 'live-chat-for-freescout' ),
				'placeholder' => __( 'Type a message…', 'live-chat-for-freescout' ),
				'send'        => __( 'Send', 'live-chat-for-freescout' ),
				'close'       => __( 'Close chat', 'live-chat-for-freescout' ),
				'retry'       => __( "Couldn't send. Tap to retry.", 'live-chat-for-freescout' ),
			),
		);

		wp_add_inline_script( 'lcff-loader', 'window.LCFF_CONFIG = ' . wp_json_encode( $config ) . ';', 'before' );
	}
}
