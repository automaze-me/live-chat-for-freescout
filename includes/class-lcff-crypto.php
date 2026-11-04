<?php
/**
 * Seals identity tokens (AES-256-GCM) that only FreeScout can open.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCFF_Crypto {

	const CONTEXT = 'lcff identity v1';

	/**
	 * True when this PHP can seal tokens.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'hash_hkdf' )
			&& function_exists( 'openssl_get_cipher_methods' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}

	/**
	 * Seals a payload into a "v1." token.
	 *
	 * @param array       $payload Payload with keys in the agreed order.
	 * @param string      $secret  The site's 43-character secret.
	 * @param string      $site_id The site's public id.
	 * @param string|null $iv      12 bytes; random when null (fixed only in tests).
	 * @return string|null Null on failure.
	 */
	public static function seal( array $payload, $secret, $site_id, $iv = null ) {
		if ( ! self::available() ) {
			return null;
		}
		if ( null === $iv ) {
			$iv = random_bytes( 12 );
		}

		$plain = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- bytes must match FreeScout.
		if ( false === $plain ) {
			return null;
		}

		$is_order = isset( $payload['typ'] ) && 'order' === $payload['typ'];
		$length   = strlen( $plain );
		$target   = $is_order ? 512 : (int) ( ceil( $length / 256 ) * 256 );
		if ( $length > $target ) {
			return null; // An oversized order payload is a bug.
		}
		$plain = str_pad( $plain, $target, ' ', STR_PAD_RIGHT );

		$key = hash_hkdf( 'sha256', $secret, 32, self::CONTEXT, '' );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::CONTEXT . '|' . $site_id, 16 );
		if ( false === $ct ) {
			return null;
		}

		return 'v1.' . rtrim( strtr( base64_encode( $iv . $ct . $tag ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url, not obfuscation.
	}
}
