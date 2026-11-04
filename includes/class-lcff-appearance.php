<?php
/**
 * Look and placement of the widget: sanitizing, hidden pages, front-end config.
 *
 * The sanitizing and matching are pure PHP so they can be tested without
 * WordPress (tests/appearance.php).
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCFF_Appearance {

	const DEFAULT_ACCENT = '#2f6f5e';

	/**
	 * Sanitizes the appearance keys present in $input. Absent keys stay absent
	 * so the caller keeps their previous values.
	 *
	 * @param array $input Raw (slashed) form input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$out = array();

		if ( isset( $input['team_name'] ) ) {
			$out['team_name'] = self::cut( sanitize_text_field( wp_unslash( (string) $input['team_name'] ) ), 60 );
		}
		if ( isset( $input['avatar_id'] ) ) {
			$out['avatar_id'] = absint( $input['avatar_id'] );
		}
		if ( isset( $input['accent'] ) ) {
			$accent         = strtolower( trim( sanitize_text_field( wp_unslash( (string) $input['accent'] ) ) ) );
			$out['accent'] = 1 === preg_match( '/^#[0-9a-f]{6}$/', $accent ) ? $accent : self::DEFAULT_ACCENT;
		}
		if ( isset( $input['position'] ) ) {
			$out['position'] = 'left' === $input['position'] ? 'left' : 'right';
		}
		foreach ( array( 'welcome', 'welcome_away' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = self::cut( sanitize_textarea_field( wp_unslash( (string) $input[ $key ] ) ), 500 );
			}
		}
		if ( isset( $input['hidden_paths'] ) ) {
			$out['hidden_paths'] = self::normalize_paths( wp_unslash( (string) $input['hidden_paths'] ) );
		}
		if ( isset( $input['privacy_url'] ) ) {
			$out['privacy_url'] = esc_url_raw( trim( wp_unslash( (string) $input['privacy_url'] ) ), array( 'http', 'https' ) );
		}

		return $out;
	}

	/**
	 * One trimmed path per line, empty lines dropped, leading slash added.
	 */
	public static function normalize_paths( $text ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( strip_tags( $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( '/' !== $line[0] ) {
				$line = '/' . $line;
			}
			$lines[] = $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * True when $path is on the hidden list. A line ending in * matches that
	 * prefix, any other line matches the path exactly; a trailing slash is
	 * ignored.
	 */
	public static function is_hidden( $path, $hidden_paths ) {
		$path = self::trim_slash( (string) $path );
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $hidden_paths ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( '*' === substr( $line, -1 ) ) {
				$prefix = substr( $line, 0, -1 );
				if ( '' !== $prefix && ( 0 === strpos( $path, $prefix ) || $path . '/' === $prefix ) ) {
					return true;
				}
			} elseif ( self::trim_slash( $line ) === $path ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The appearance part of LCFF_CONFIG. Empty values fall back to defaults.
	 *
	 * @param array $s Stored settings.
	 * @return array
	 */
	public static function config( array $s ) {
		$avatar = '';
		if ( ! empty( $s['avatar_id'] ) ) {
			$url    = wp_get_attachment_image_url( (int) $s['avatar_id'], 'thumbnail' );
			$avatar = $url ? (string) $url : '';
		}
		$privacy = ! empty( $s['privacy_url'] ) ? (string) $s['privacy_url'] : (string) get_privacy_policy_url();

		return array(
			'accent'      => ! empty( $s['accent'] ) ? $s['accent'] : self::DEFAULT_ACCENT,
			'position'    => ( isset( $s['position'] ) && 'left' === $s['position'] ) ? 'left' : 'right',
			'teamName'    => ! empty( $s['team_name'] ) ? $s['team_name'] : __( 'Chat with us', 'live-chat-for-freescout' ),
			'avatarUrl'   => $avatar,
			'welcome'     => ! empty( $s['welcome'] ) ? $s['welcome'] : __( 'Hi! How can we help?', 'live-chat-for-freescout' ),
			'welcomeAway' => ! empty( $s['welcome_away'] ) ? $s['welcome_away'] : __( "We're away right now. Leave a message and we'll answer by email.", 'live-chat-for-freescout' ),
			'privacyUrl'  => $privacy,
		);
	}

	private static function trim_slash( $path ) {
		$path = rtrim( $path, '/' );
		return '' === $path ? '/' : $path;
	}

	private static function cut( $text, $max ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max );
		}
		return implode( '', array_slice( preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array(), 0, $max ) );
	}
}
