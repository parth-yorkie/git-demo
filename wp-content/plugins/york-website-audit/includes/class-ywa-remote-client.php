<?php
/**
 * Authenticated WordPress REST client for remote (dropdown) audit targets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Remote_Client {

	/** @var array|null Cached snapshot for this request. */
	private static $snapshot = null;

	/**
	 * Whether a remote target is available for API checks.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( ! class_exists( 'YWA_Front_Target' ) || ! YWA_Front_Target::active() ) {
			return false;
		}
		$t = YWA_Front_Target::get();
		return is_array( $t ) && ! empty( $t['url'] ) && ! empty( $t['username'] ) && ! empty( $t['password'] );
	}

	/**
	 * Fetch (and cache) remote site security snapshot.
	 *
	 * @return array|WP_Error
	 */
	public static function security_snapshot() {
		if ( is_array( self::$snapshot ) ) {
			return self::$snapshot;
		}
		if ( ! self::available() ) {
			return new WP_Error( 'ywa_remote', __( 'No remote target is active.', 'york-website-audit' ) );
		}

		$t        = YWA_Front_Target::get();
		$base     = untrailingslashit( (string) $t['url'] );
		$username = (string) $t['username'];
		$password = (string) $t['password'];

		$plugins = self::get_collection( $base . '/wp-json/wp/v2/plugins', $username, $password );
		if ( is_wp_error( $plugins ) ) {
			return $plugins;
		}

		$users = self::get_collection( $base . '/wp-json/wp/v2/users', $username, $password, array( 'context' => 'edit' ) );
		if ( is_wp_error( $users ) ) {
			$users = self::get_collection( $base . '/wp-json/wp/v2/users', $username, $password );
		}
		if ( is_wp_error( $users ) ) {
			$users = array();
		}

		$admins             = 0;
		$has_admin_username = false;
		foreach ( $users as $user ) {
			if ( ! is_array( $user ) ) {
				continue;
			}
			$roles = isset( $user['roles'] ) && is_array( $user['roles'] ) ? $user['roles'] : array();
			if ( in_array( 'administrator', $roles, true ) ) {
				++$admins;
			}
			$slug = isset( $user['slug'] ) ? strtolower( (string) $user['slug'] ) : '';
			if ( 'admin' === $slug ) {
				$has_admin_username = true;
			}
		}

		$active_plugins = array();
		foreach ( $plugins as $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}
			$status = isset( $plugin['status'] ) ? (string) $plugin['status'] : '';
			$file   = isset( $plugin['plugin'] ) ? (string) $plugin['plugin'] : '';
			if ( 'active' === $status || 'network-active' === $status ) {
				$active_plugins[] = $file;
			}
		}

		self::$snapshot = array(
			'base'               => $base,
			'admins'             => $admins,
			'has_admin_username' => $has_admin_username,
			'users_fetched'      => ! empty( $users ),
			'plugins'            => $plugins,
			'active_plugins'     => $active_plugins,
			'plugin_updates'     => self::count_plugin_updates( $plugins ),
			'is_https'           => ( 0 === strpos( $base, 'https://' ) ),
		);

		return self::$snapshot;
	}

	/**
	 * Count plugins with a newer version on wordpress.org (and any REST update payloads).
	 *
	 * @param array $plugins Plugin rows from REST.
	 * @return int
	 */
	private static function count_plugin_updates( array $plugins ) {
		$count   = 0;
		$checked = 0;

		foreach ( $plugins as $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}
			if ( ! empty( $plugin['update'] ) && is_array( $plugin['update'] ) ) {
				++$count;
				continue;
			}
			if ( $checked >= 45 ) {
				continue;
			}

			$file    = isset( $plugin['plugin'] ) ? (string) $plugin['plugin'] : '';
			$version = isset( $plugin['version'] ) ? (string) $plugin['version'] : '';
			if ( '' === $file || '' === $version ) {
				continue;
			}

			$slug = ( false === strpos( $file, '/' ) )
				? basename( $file, '.php' )
				: dirname( $file );
			if ( '' === $slug || '.' === $slug ) {
				continue;
			}

			++$checked;
			$org = self::org_plugin_version( $slug );
			if ( $org && version_compare( $org, $version, '>' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param string $slug Plugin slug.
	 * @return string Newest org version or empty.
	 */
	private static function org_plugin_version( $slug ) {
		$url      = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=' . rawurlencode( $slug );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) || empty( $data['version'] ) ) {
			return '';
		}
		return (string) $data['version'];
	}

	/**
	 * Whether an active remote plugin path matches any needle.
	 *
	 * @param array    $snapshot Snapshot.
	 * @param string[] $needles Needles.
	 * @return bool
	 */
	public static function has_active_plugin( array $snapshot, array $needles ) {
		$active = isset( $snapshot['active_plugins'] ) && is_array( $snapshot['active_plugins'] ) ? $snapshot['active_plugins'] : array();
		foreach ( $active as $plugin ) {
			foreach ( $needles as $needle ) {
				if ( false !== stripos( (string) $plugin, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @param string      $endpoint Base endpoint without query.
	 * @param string|null $username Username.
	 * @param string|null $password Password.
	 * @param array       $query Extra query args.
	 * @return array|WP_Error
	 */
	private static function get_collection( $endpoint, $username, $password, array $query = array() ) {
		$items = array();
		$page  = 1;
		$max   = 10;

		while ( $page <= $max ) {
			$args               = $query;
			$args['per_page']   = 100;
			$args['page']       = $page;
			$url                = add_query_arg( $args, $endpoint );

			$response = self::remote_get( $url, $username, $password );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 401 === $code || 403 === $code ) {
				return new WP_Error(
					'ywa_remote_auth',
					__( 'Remote site rejected Application Password for this request. Ensure the user can manage plugins/users.', 'york-website-audit' )
				);
			}
			if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
				return new WP_Error(
					'ywa_remote_http',
					sprintf(
						/* translators: %s: HTTP status */
						__( 'Remote API error (%s).', 'york-website-audit' ),
						(string) $code
					)
				);
			}

			if ( ! self::is_list( $body ) ) {
				$items[] = $body;
				break;
			}

			foreach ( $body as $row ) {
				$items[] = $row;
			}

			$total_pages = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );
			if ( $total_pages < 1 || $page >= $total_pages || empty( $body ) ) {
				break;
			}
			++$page;
		}

		return $items;
	}

	/**
	 * @param string      $url URL.
	 * @param string|null $username Username.
	 * @param string|null $password Password.
	 * @return array|WP_Error
	 */
	private static function remote_get( $url, $username, $password ) {
		$args = array(
			'timeout'     => 30,
			'redirection' => 3,
			'headers'     => array(
				'Accept' => 'application/json',
			),
		);
		if ( null !== $username && null !== $password && '' !== $username && '' !== $password ) {
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return wp_remote_get( $url, $args );
	}

	/**
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function is_list( array $arr ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}
		if ( array() === $arr ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}
}
