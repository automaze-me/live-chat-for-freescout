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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
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
				'secret'        => '',
				'team_name'     => '',
				'avatar_id'     => 0,
				'accent'        => LCFF_Appearance::DEFAULT_ACCENT,
				'position'      => 'right',
				'welcome'       => '',
				'welcome_away'  => '',
				'hidden_paths'  => '',
				'privacy_url'   => '',
				'hours'         => array_fill( 0, 7, null ),
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

	public static function is_valid_secret( $secret ) {
		return is_string( $secret ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $secret );
	}

	/**
	 * True when the secret is valid and this PHP can seal tokens.
	 *
	 * @return bool
	 */
	public static function identity_enabled() {
		$s = self::get();
		return self::is_valid_secret( $s['secret'] ) && self::is_valid_site_id( $s['site_id'] ) && LCFF_Crypto::available();
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

	/**
	 * Media modal and color picker, on this settings page only.
	 *
	 * @param string $hook Admin page hook suffix.
	 */
	public static function admin_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'lcff-admin', LCFF_PLUGIN_URL . 'assets/js/lcff-admin.js', array( 'wp-color-picker' ), LCFF_VERSION, true );
		wp_localize_script(
			'lcff-admin',
			'LCFF_ADMIN',
			array(
				'chooseTitle'  => __( 'Choose an avatar', 'live-chat-for-freescout' ),
				'chooseButton' => __( 'Use this image', 'live-chat-for-freescout' ),
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'testAction'   => LCFF_Connection::ACTION,
				'testNonce'    => wp_create_nonce( LCFF_Connection::NONCE ),
				/* translators: %s: the error from the request */
				'unreachable'  => __( "Couldn't reach FreeScout: %s", 'live-chat-for-freescout' ),
			)
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

		add_settings_field(
			'secret',
			__( 'Secret', 'live-chat-for-freescout' ),
			array( __CLASS__, 'render_field' ),
			self::PAGE,
			'lcff_main',
			array(
				'key'         => 'secret',
				'type'        => 'password',
				'placeholder' => '',
				'description' => __( 'The 43-character site secret from FreeScout. It lets logged-in visitors and order numbers be recognized. Leave blank to keep the saved value.', 'live-chat-for-freescout' ),
			)
		);

		add_settings_section(
			'lcff_appearance',
			__( 'Appearance', 'live-chat-for-freescout' ),
			'__return_empty_string',
			self::PAGE
		);

		$fields = array(
			array( 'team_name', __( 'Team name', 'live-chat-for-freescout' ), 'text', '' ),
			array( 'avatar_id', __( 'Avatar', 'live-chat-for-freescout' ), 'avatar', '' ),
			array( 'accent', __( 'Accent color', 'live-chat-for-freescout' ), 'accent', '' ),
			array( 'position', __( 'Position', 'live-chat-for-freescout' ), 'position', '' ),
			array( 'welcome', __( 'Welcome message', 'live-chat-for-freescout' ), 'textarea', '' ),
			array( 'welcome_away', __( 'Welcome message (away)', 'live-chat-for-freescout' ), 'textarea', '' ),
			array( 'hidden_paths', __( 'Hide on pages', 'live-chat-for-freescout' ), 'textarea', __( 'One path per line, e.g. /checkout/. A line ending in * hides the chat on every page below it.', 'live-chat-for-freescout' ) ),
			array( 'privacy_url', __( 'Privacy policy link', 'live-chat-for-freescout' ), 'url', __( 'Leave empty to use the WordPress privacy page.', 'live-chat-for-freescout' ) ),
		);
		add_settings_field(
			'hours',
			__( 'Working hours', 'live-chat-for-freescout' ),
			array( __CLASS__, 'render_hours_field' ),
			self::PAGE,
			'lcff_appearance'
		);
		foreach ( $fields as $f ) {
			add_settings_field(
				$f[0],
				$f[1],
				array( __CLASS__, 'render_appearance_field' ),
				self::PAGE,
				'lcff_appearance',
				array(
					'key'         => $f[0],
					'type'        => $f[2],
					'description' => $f[3],
					'label_for'   => 'lcff_' . $f[0],
				)
			);
		}
	}

	/**
	 * Renders the seven working-hours rows.
	 */
	public static function render_hours_field() {
		$s     = self::get();
		$hours = self::sanitize_hours( is_array( $s['hours'] ) ? $s['hours'] : array() );
		$days  = self::day_names();
		foreach ( $days as $i => $label ) {
			$range = $hours[ $i ];
			$base  = self::OPTION . '[hours][' . $i . ']';
			$id    = 'lcff_hours_' . $i;
			$to    = $range ? ( '24:00' === $range[1] ? '00:00' : $range[1] ) : '17:00';
			echo '<p style="margin:0 0 6px;">';
			printf( '<strong style="display:inline-block;min-width:6em;">%1$s</strong> <label for="%2$s_open" style="display:inline-block;min-width:6em;"><input type="checkbox" id="%2$s_open" name="%3$s[open]" value="1"%4$s /> %5$s</label> ', esc_html( $label ), esc_attr( $id ), esc_attr( $base ), checked( null !== $range, true, false ), esc_html__( 'Open', 'live-chat-for-freescout' ) );
			printf( '<label for="%1$s_from">%2$s</label> <input type="time" id="%1$s_from" name="%3$s[from]" value="%4$s" /> ', esc_attr( $id ), esc_html__( 'from', 'live-chat-for-freescout' ), esc_attr( $base ), esc_attr( $range ? $range[0] : '09:00' ) );
			printf( '<label for="%1$s_to">%2$s</label> <input type="time" id="%1$s_to" name="%3$s[to]" value="%4$s" />', esc_attr( $id ), esc_html__( 'to', 'live-chat-for-freescout' ), esc_attr( $base ), esc_attr( $to ) );
			echo '</p>';
		}
		$tz = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '';
		/* translators: %s: the site's timezone, e.g. Europe/Berlin or +02:00 */
		echo '<p class="description">' . esc_html( sprintf( __( "Times use the site's timezone (%s).", 'live-chat-for-freescout' ), $tz ) ) . '</p>';
	}

	/**
	 * Renders one appearance field.
	 *
	 * @param array $args Field args.
	 */
	public static function render_appearance_field( $args ) {
		$s    = self::get();
		$key  = $args['key'];
		$name = self::OPTION . '[' . $key . ']';
		$id   = 'lcff_' . $key;
		$val  = isset( $s[ $key ] ) ? $s[ $key ] : '';

		switch ( $args['type'] ) {
			case 'avatar':
				$url = $val ? wp_get_attachment_image_url( (int) $val, 'thumbnail' ) : '';
				printf( '<input type="hidden" id="%1$s" name="%2$s" value="%3$s" />', esc_attr( $id ), esc_attr( $name ), esc_attr( (int) $val ) );
				printf(
					'<p><img id="lcff_avatar_preview" src="%1$s" alt="" style="width:64px;height:64px;border-radius:50%%;object-fit:cover;%2$s" /></p>',
					esc_url( (string) $url ),
					$url ? '' : 'display:none;'
				);
				echo '<button type="button" class="button" id="lcff_avatar_choose">' . esc_html__( 'Choose an avatar', 'live-chat-for-freescout' ) . '</button> ';
				echo '<button type="button" class="button-link" id="lcff_avatar_remove"' . ( $url ? '' : ' style="display:none;"' ) . '>' . esc_html__( 'Remove', 'live-chat-for-freescout' ) . '</button>';
				break;
			case 'accent':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="lcff-color-field" data-default-color="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $val ),
					esc_attr( LCFF_Appearance::DEFAULT_ACCENT )
				);
				break;
			case 'position':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				printf( '<option value="right"%1$s>%2$s</option>', selected( $val, 'right', false ), esc_html__( 'Bottom right', 'live-chat-for-freescout' ) );
				printf( '<option value="left"%1$s>%2$s</option>', selected( $val, 'left', false ), esc_html__( 'Bottom left', 'live-chat-for-freescout' ) );
				echo '</select>';
				break;
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text code" %4$s>%5$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					'hidden_paths' === $key ? 4 : 2,
					'hidden_paths' === $key ? '' : 'maxlength="500"',
					esc_textarea( $val )
				);
				break;
			default:
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text" %5$s />',
					esc_attr( $args['type'] ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $val ),
					'team_name' === $key ? 'maxlength="60"' : ''
				);
		}
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Sanitizes the submitted settings. Idempotent: on the first save, when
	 * the option does not exist yet, WordPress runs this twice (update_option()
	 * sanitizes, then add_option() sanitizes that result again, WP trac
	 * #21989), so an already sanitized value must come back unchanged. The
	 * input is already unslashed (options.php does that).
	 *
	 * @param mixed $input Form input, or an already sanitized value.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::get();

		$raw_url              = self::text( $input, 'freescout_url' );
		list( $url, $url_err ) = self::validate_url( $raw_url, self::site_is_https() );
		if ( 'https_required' === $url_err ) {
			add_settings_error( self::OPTION, 'lcff_url_https', __( 'Your site uses HTTPS, so the FreeScout URL must start with https://.', 'live-chat-for-freescout' ) );
		} elseif ( null !== $url_err ) {
			add_settings_error( self::OPTION, 'lcff_url', __( 'The FreeScout URL is not valid.', 'live-chat-for-freescout' ) );
		} else {
			$out['freescout_url'] = $url;
		}

		$site_id = strtolower( trim( sanitize_text_field( self::text( $input, 'site_id' ) ) ) );
		if ( '' === $site_id ) {
			$out['site_id'] = '';
		} elseif ( self::is_valid_site_id( $site_id ) ) {
			$out['site_id'] = $site_id;
		} else {
			add_settings_error( self::OPTION, 'lcff_site_id', __( 'The Site ID must be 32 lowercase hexadecimal characters.', 'live-chat-for-freescout' ) );
		}

		$secret = trim( sanitize_text_field( self::text( $input, 'secret' ) ) );
		if ( '' !== $secret ) {
			if ( self::is_valid_secret( $secret ) ) {
				$out['secret'] = $secret;
			} else {
				add_settings_error( self::OPTION, 'lcff_secret', __( 'The secret must be 43 characters: letters, digits, "-" and "_".', 'live-chat-for-freescout' ) );
			}
		} elseif ( ! empty( $input['secret_clear'] ) ) {
			$out['secret'] = '';
		}

		$out = array_merge( $out, LCFF_Appearance::sanitize( $input ) );

		if ( isset( $input['hours'] ) && is_array( $input['hours'] ) ) {
			$list         = self::hours_from_form( $input['hours'] );
			$out['hours'] = self::sanitize_hours( $list );
			$names        = self::day_names();
			foreach ( $list as $i => $entry ) {
				if ( $i < 7 && is_array( $entry ) && null === $out['hours'][ $i ] ) {
					/* translators: %s: the day of the week */
					add_settings_error( self::OPTION, 'lcff_hours_' . $i, sprintf( __( 'Working hours for %s: the end must be later than the start. The day was saved as closed.', 'live-chat-for-freescout' ), $names[ $i ] ) );
				}
			}
		}

		return $out;
	}

	/**
	 * A posted value as a string; anything but a string (an array) is ''.
	 *
	 * @param array  $input Form input.
	 * @param string $key   Key.
	 * @return string
	 */
	private static function text( array $input, $key ) {
		return ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) ? $input[ $key ] : '';
	}

	/**
	 * True when the site is served over HTTPS.
	 *
	 * @return bool
	 */
	private static function site_is_https() {
		if ( function_exists( 'is_ssl' ) && is_ssl() ) {
			return true;
		}
		return function_exists( 'home_url' ) && 0 === strpos( (string) home_url(), 'https://' );
	}

	/**
	 * Checks a FreeScout URL.
	 *
	 * @param mixed $raw            Submitted value.
	 * @param bool  $site_is_https  Whether this WordPress site uses HTTPS.
	 * @return array array( url or '', error code or null ): 'invalid' or 'https_required'.
	 */
	public static function validate_url( $raw, $site_is_https ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return array( '', null );
		}
		$scheme = strtolower( (string) parse_url( $raw, PHP_URL_SCHEME ) );
		$host   = (string) parse_url( $raw, PHP_URL_HOST );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return array( '', 'invalid' );
		}
		$url = untrailingslashit( esc_url_raw( $raw, array( 'http', 'https' ) ) );
		if ( '' === $url ) {
			return array( '', 'invalid' );
		}
		// Browsers treat loopback hosts as secure contexts: no mixed-content block.
		$loopback = in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '[::1]' ), true );
		if ( $site_is_https && 'http' === $scheme && ! $loopback ) {
			return array( '', 'https_required' );
		}
		return array( $url, null );
	}

	/**
	 * Day names, Monday first.
	 *
	 * @return array
	 */
	private static function day_names() {
		return array(
			__( 'Monday', 'live-chat-for-freescout' ),
			__( 'Tuesday', 'live-chat-for-freescout' ),
			__( 'Wednesday', 'live-chat-for-freescout' ),
			__( 'Thursday', 'live-chat-for-freescout' ),
			__( 'Friday', 'live-chat-for-freescout' ),
			__( 'Saturday', 'live-chat-for-freescout' ),
			__( 'Sunday', 'live-chat-for-freescout' ),
		);
	}

	/**
	 * Turns the submitted rows (open checkbox, from, to; Monday first) into
	 * the list form sanitize_hours() takes. A row already in that form (a
	 * two-element list, or null) passes through, so a second sanitize pass
	 * keeps the hours (see sanitize()).
	 *
	 * @param array $rows Form rows, or sanitized entries.
	 * @return array
	 */
	public static function hours_from_form( array $rows ) {
		$list = array();
		foreach ( array_values( $rows ) as $row ) {
			if ( is_array( $row ) && array( 0, 1 ) === array_keys( $row ) ) {
				$list[] = array( $row[0], $row[1] );
			} elseif ( is_array( $row ) && ! empty( $row['open'] ) ) {
				$from = ( isset( $row['from'] ) && is_string( $row['from'] ) ) ? $row['from'] : '';
				$to   = ( isset( $row['to'] ) && is_string( $row['to'] ) ) ? $row['to'] : '';
				if ( '00:00' === $to && '' !== $from && '00:00' !== $from ) {
					$to = '24:00'; // A time input can't show 24:00: an end of midnight means the end of the day.
				}
				$list[] = array( $from, $to );
			} else {
				$list[] = null;
			}
		}
		return $list;
	}

	/**
	 * Seven entries, Monday first: null (closed) or array( 'HH:MM', 'HH:MM' )
	 * with from < to; the end may be 24:00 (end of the day). Anything else
	 * becomes null. No overnight ranges.
	 *
	 * @param array $input Entries.
	 * @return array
	 */
	public static function sanitize_hours( array $input ) {
		$input = array_values( $input );
		$out   = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$out[ $i ] = null;
			$e         = isset( $input[ $i ] ) ? $input[ $i ] : null;
			if ( ! is_array( $e ) || 2 !== count( $e ) ) {
				continue;
			}
			$e    = array_values( $e );
			$from = is_string( $e[0] ) ? $e[0] : '';
			$to   = is_string( $e[1] ) ? $e[1] : '';
			$re   = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
			if ( 1 === preg_match( $re, $from ) && ( 1 === preg_match( $re, $to ) || '24:00' === $to ) && strcmp( $from, $to ) < 0 ) {
				$out[ $i ] = array( $from, $to );
			}
		}
		return $out;
	}

	public static function render_field( $args ) {
		$s        = self::get();
		$key      = $args['key'];
		$value    = ( 'password' !== $args['type'] && isset( $s[ $key ] ) ) ? $s[ $key ] : '';
		$stored   = is_string( $s[ $key ] ) ? $s[ $key ] : '';
		$is_secret = 'password' === $args['type'];
		$invalid  = $is_secret && '' !== $stored && ! self::is_valid_secret( $stored );
		if ( $is_secret && '' !== $stored && ! $invalid ) {
			$args['placeholder'] = __( 'Saved — leave blank to keep', 'live-chat-for-freescout' );
		}
		printf(
			'<input type="%1$s" id="lcff_%2$s" name="%3$s[%2$s]" value="%4$s" placeholder="%5$s" class="regular-text" autocomplete="off" />',
			esc_attr( $args['type'] ),
			esc_attr( $key ),
			esc_attr( self::OPTION ),
			esc_attr( $value ),
			esc_attr( $args['placeholder'] )
		);
		if ( $invalid ) {
			echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'The saved secret is not valid. Paste it again from FreeScout.', 'live-chat-for-freescout' ) . '</p>';
		}
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
		if ( $is_secret ) {
			if ( '' !== $stored ) {
				printf( '<p><label for="lcff_secret_clear"><input type="checkbox" id="lcff_secret_clear" name="%1$s[secret_clear]" value="1" /> %2$s</label></p>', esc_attr( self::OPTION ), esc_html__( 'Remove the saved secret', 'live-chat-for-freescout' ) );
			}
			if ( ! LCFF_Crypto::available() ) {
				echo '<p class="description" style="color:#b32d2e;">' . esc_html__( "This server's PHP can't encrypt with AES-256-GCM (OpenSSL), so logged-in visitors and order numbers can't be recognized.", 'live-chat-for-freescout' ) . '</p>';
			}
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
			<p>
				<button type="button" class="button" id="lcff_test_connection"><?php echo esc_html__( 'Test connection', 'live-chat-for-freescout' ); ?></button>
			</p>
			<p id="lcff_test_result" aria-live="polite"></p>
		</div>
		<?php
	}
}
