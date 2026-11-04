<?php
/**
 * Settings page and option handling.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCFF_Settings {

	const OPTION = 'lcff_settings';
	const GROUP  = 'lcff_settings_group';
	const PAGE   = 'live-chat-for-freescout';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Returns the stored settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge(
			array(
				'freescout_url' => '',
				'site_id'       => '',
			),
			$stored
		);
	}

	/**
	 * True when both settings are present and valid.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$s = self::get();
		return '' !== $s['freescout_url'] && self::is_valid_site_id( $s['site_id'] );
	}

	public static function is_valid_site_id( $site_id ) {
		return is_string( $site_id ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $site_id );
	}

	public static function add_menu() {
		add_options_page(
			__( 'Live Chat for FreeScout', 'live-chat-for-freescout' ),
			__( 'Live Chat for FreeScout', 'live-chat-for-freescout' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'lcff_main',
			'',
			'__return_empty_string',
			self::PAGE
		);

		add_settings_field(
			'freescout_url',
			__( 'FreeScout URL', 'live-chat-for-freescout' ),
			array( __CLASS__, 'render_field' ),
			self::PAGE,
			'lcff_main',
			array(
				'key'         => 'freescout_url',
				'type'        => 'url',
				'placeholder' => 'https://support.example.com',
				'description' => __( 'The address of your FreeScout installation.', 'live-chat-for-freescout' ),
			)
		);

		add_settings_field(
			'site_id',
			__( 'Site ID', 'live-chat-for-freescout' ),
			array( __CLASS__, 'render_field' ),
			self::PAGE,
			'lcff_main',
			array(
				'key'         => 'site_id',
				'type'        => 'text',
				'placeholder' => '0123456789abcdef0123456789abcdef',
				'description' => __( 'The 32-character site ID, shown in FreeScout under Manage → Website Chat when you open your website.', 'live-chat-for-freescout' ),
			)
		);
	}

	/**
	 * Sanitizes the submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::get();

		$url = isset( $input['freescout_url'] ) ? esc_url_raw( trim( wp_unslash( (string) $input['freescout_url'] ) ), array( 'http', 'https' ) ) : '';
		$url = untrailingslashit( $url );
		if ( '' === $url && ! empty( $input['freescout_url'] ) ) {
			add_settings_error( self::OPTION, 'lcff_url', __( 'The FreeScout URL is not valid.', 'live-chat-for-freescout' ) );
		} else {
			$out['freescout_url'] = $url;
		}

		$site_id = isset( $input['site_id'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( (string) $input['site_id'] ) ) ) ) : '';
		if ( '' === $site_id ) {
			$out['site_id'] = '';
		} elseif ( self::is_valid_site_id( $site_id ) ) {
			$out['site_id'] = $site_id;
		} else {
			add_settings_error( self::OPTION, 'lcff_site_id', __( 'The Site ID must be 32 lowercase hexadecimal characters.', 'live-chat-for-freescout' ) );
		}

		return $out;
	}

	public static function render_field( $args ) {
		$s     = self::get();
		$key   = $args['key'];
		$value = isset( $s[ $key ] ) ? $s[ $key ] : '';
		printf(
			'<input type="%1$s" id="lcff_%2$s" name="%3$s[%2$s]" value="%4$s" placeholder="%5$s" class="regular-text" autocomplete="off" />',
			esc_attr( $args['type'] ),
			esc_attr( $key ),
			esc_attr( self::OPTION ),
			esc_attr( $value ),
			esc_attr( $args['placeholder'] )
		);
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
