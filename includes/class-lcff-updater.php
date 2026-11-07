<?php
/**
 * Updates from the plugin's GitHub releases.
 *
 * The plugin header's "Update URI" points at the GitHub repository, so
 * WordPress (5.8+) never asks wordpress.org about this plugin and instead
 * runs the update_plugins_github.com filter. The answer comes from the
 * update.json the release workflow attaches to every release; WordPress
 * compares the version and offers the update, and "View details" shows
 * the same data (plugins_api).
 *
 * The package URL is built from the checked version, so the manifest can
 * only ever point at this repository's own release zip.
 *
 * @package LiveChatForFreeScout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCFF_Updater {

	const REPO_URL  = 'https://github.com/automaze-me/live-chat-for-freescout';
	const SLUG      = 'live-chat-for-freescout';
	const TRANSIENT = 'lcff_update_manifest';

	/**
	 * Registers the update check and the details box.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
	}

	/**
	 * The update data for this plugin, or $update unchanged for any other
	 * plugin (or when GitHub gives no usable answer).
	 *
	 * @param array|false $update      Data another filter returned, or false.
	 * @param array       $plugin_data Plugin header data.
	 * @param string      $plugin_file Plugin basename.
	 * @param string[]    $locales     Installed locales.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( LCFF_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}
		$m = self::manifest();
		if ( null === $m ) {
			return $update;
		}
		$out = array(
			'slug'    => self::SLUG,
			'version' => $m['version'],
			'url'     => self::release_url( $m['version'] ),
			'package' => self::package_url( $m['version'] ),
		);
		foreach ( array( 'requires', 'requires_php', 'tested' ) as $key ) {
			if ( isset( $m[ $key ] ) ) {
				$out[ $key ] = $m[ $key ];
			}
		}
		return $out;
	}

	/**
	 * The "View details" box for this plugin; anything else passes through.
	 *
	 * @param false|object|array $result The result so far.
	 * @param string             $action The plugins_api action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$m = self::manifest();
		if ( null === $m ) {
			return $result;
		}
		$release = self::release_url( $m['version'] );
		$info    = array(
			'name'          => 'Live Chat for FreeScout',
			'slug'          => self::SLUG,
			'version'       => $m['version'],
			'author'        => '<a href="https://automaze.me">automaze.me</a>',
			'homepage'      => self::REPO_URL,
			'download_link' => self::package_url( $m['version'] ),
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Adds a live chat bubble to your site that delivers visitor messages to your FreeScout help desk.', 'live-chat-for-freescout' ) . '</p>',
				'changelog'   => '<p><a href="' . esc_url( $release ) . '">' . esc_html__( 'Release notes on GitHub', 'live-chat-for-freescout' ) . '</a></p>',
			),
		);
		foreach ( array( 'requires', 'requires_php', 'tested' ) as $key ) {
			if ( isset( $m[ $key ] ) ) {
				$info[ $key ] = $m[ $key ];
			}
		}
		return (object) $info;
	}

	/**
	 * The latest release's update.json, checked, or null. Cached for an
	 * hour, a failure too, so a slow or missing GitHub costs one request.
	 *
	 * @return array|null array( 'version' => 'x.y.z', and optionally 'requires', 'requires_php', 'tested' ).
	 */
	private static function manifest() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return isset( $cached['version'] ) ? $cached : null;
		}
		$m = self::fetch();
		set_site_transient( self::TRANSIENT, null === $m ? array( 'failed' => true ) : $m, HOUR_IN_SECONDS );
		return $m;
	}

	/**
	 * Downloads and checks update.json.
	 *
	 * @return array|null
	 */
	private static function fetch() {
		$response = wp_remote_get( self::REPO_URL . '/releases/latest/download/update.json', array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['version'] ) || ! is_string( $data['version'] ) || 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $data['version'] ) ) {
			return null;
		}
		$m = array( 'version' => $data['version'] );
		foreach ( array( 'requires', 'requires_php', 'tested' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && 1 === preg_match( '/^\d+(?:\.\d+){0,2}$/', $data[ $key ] ) ) {
				$m[ $key ] = $data[ $key ];
			}
		}
		return $m;
	}

	private static function release_url( $version ) {
		return self::REPO_URL . '/releases/tag/v' . $version;
	}

	private static function package_url( $version ) {
		return self::REPO_URL . '/releases/download/v' . $version . '/live-chat-for-freescout.zip';
	}
}
