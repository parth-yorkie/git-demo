<?php
/**
 * Frontend-only remote target context for Website Audit.
 * Backend/admin runs remain local and ignore this.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Front_Target {

	/** @var array|null */
	private static $target = null;

	/**
	 * @return void
	 */
	public static function boot() {
		add_filter( 'ywa_lighthouse_url', array( __CLASS__, 'filter_lighthouse_url' ), 20 );
	}

	/**
	 * Read and validate target from the current request (frontend audit only).
	 *
	 * @return true|WP_Error
	 */
	public static function load_from_request() {
		$site_id = isset( $_POST['site_id'] ) ? sanitize_key( wp_unslash( $_POST['site_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$label   = '';

		if ( $site_id && function_exists( 'york_sites_get' ) ) {
			$site = york_sites_get( $site_id );
			if ( ! $site ) {
				return new WP_Error( 'ywa_target_site', __( 'Saved website not found. Add it again from Add Website.', 'york-website-audit' ) );
			}
			$url   = isset( $site['url'] ) ? (string) $site['url'] : '';
			$user  = isset( $site['username'] ) ? (string) $site['username'] : '';
			$pass  = isset( $site['app_password'] ) ? (string) $site['app_password'] : '';
			$label = ! empty( $site['label'] ) ? (string) $site['label'] : '';
		} else {
			$url  = isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$user = isset( $_POST['target_user'] ) ? sanitize_text_field( wp_unslash( $_POST['target_user'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$pass = isset( $_POST['target_app_password'] ) ? (string) wp_unslash( $_POST['target_app_password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$url = self::normalize_url( $url );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$user = trim( $user );
		$pass = preg_replace( '/\s+/', '', $pass );

		if ( '' === $user || '' === $pass ) {
			return new WP_Error( 'ywa_target_auth', __( 'WordPress username and Application Password are required.', 'york-website-audit' ) );
		}

		$auth = self::probe( $url, $user, $pass );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$name = ! empty( $auth['name'] ) ? $auth['name'] : ( $label ? $label : $url );

		self::$target = array(
			'url'      => $url,
			'name'     => $name,
			'user'     => ! empty( $auth['user'] ) ? $auth['user'] : $user,
			'username' => $user,
			'password' => $pass,
		);

		return true;
	}

	/**
	 * Whether a frontend remote target is active for this request.
	 *
	 * @return bool
	 */
	public static function active() {
		return is_array( self::$target ) && ! empty( self::$target['url'] );
	}

	/**
	 * @return array|null
	 */
	public static function get() {
		return self::$target;
	}

	/**
	 * Site blob for audit reports.
	 *
	 * @return array|null
	 */
	public static function site() {
		if ( ! self::active() ) {
			return null;
		}
		return array(
			'name' => self::$target['name'],
			'url'  => self::$target['url'],
		);
	}

	/**
	 * @param string $url Default lighthouse URL.
	 * @return string
	 */
	public static function filter_lighthouse_url( $url ) {
		if ( self::active() ) {
			return self::$target['url'];
		}
		return $url;
	}

	/**
	 * @param string $url Raw URL.
	 * @return string|WP_Error
	 */
	private static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return new WP_Error( 'ywa_target_url', __( 'Please enter a website URL.', 'york-website-audit' ) );
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'ywa_target_url', __( 'That does not look like a valid URL.', 'york-website-audit' ) );
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
		$host   = strtolower( $parts['host'] );
		$path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		if ( '/wp-admin' === $path || 0 === strpos( $path, '/wp-admin/' ) ) {
			$path = '';
		}
		return $scheme . '://' . $host . $path;
	}

	/**
	 * @param string $base Base URL.
	 * @param string $username Username.
	 * @param string $password App password.
	 * @return array|WP_Error
	 */
	private static function probe( $base, $username, $password ) {
		$index = wp_remote_get(
			$base . '/wp-json/',
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $index ) ) {
			return new WP_Error(
				'ywa_target_unreachable',
				sprintf(
					/* translators: %s: error */
					__( 'Could not reach the site REST API: %s', 'york-website-audit' ),
					$index->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $index );
		$body = json_decode( wp_remote_retrieve_body( $index ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new WP_Error( 'ywa_target_not_wp', __( 'This URL does not appear to expose the WordPress REST API (/wp-json/).', 'york-website-audit' ) );
		}

		$me = wp_remote_get(
			$base . '/wp-json/wp/v2/users/me?context=edit',
			array(
				'timeout' => 25,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			)
		);
		if ( is_wp_error( $me ) ) {
			return new WP_Error(
				'ywa_target_auth',
				sprintf(
					/* translators: %s: error */
					__( 'Authentication failed: %s', 'york-website-audit' ),
					$me->get_error_message()
				)
			);
		}

		$me_code = (int) wp_remote_retrieve_response_code( $me );
		$me_body = json_decode( wp_remote_retrieve_body( $me ), true );
		if ( 401 === $me_code || 403 === $me_code ) {
			return new WP_Error( 'ywa_target_auth', __( 'Invalid username or Application Password, or the user lacks permission.', 'york-website-audit' ) );
		}
		if ( $me_code < 200 || $me_code >= 300 || ! is_array( $me_body ) ) {
			return new WP_Error( 'ywa_target_auth', __( 'Could not verify Application Password against the target site.', 'york-website-audit' ) );
		}

		return array(
			'name' => isset( $body['name'] ) ? (string) $body['name'] : '',
			'user' => isset( $me_body['slug'] ) ? (string) $me_body['slug'] : $username,
		);
	}
}
