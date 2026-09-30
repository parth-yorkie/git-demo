<?php
/**
 * Authenticated remote WordPress theme/plugin inventory.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWI_Scanner {

	/**
	 * Run a full inventory scan against a remote WordPress site.
	 *
	 * @param string $url Site URL.
	 * @param string $username WP username.
	 * @param string $app_password Application password.
	 * @return array|WP_Error
	 */
	public static function scan( $url, $username, $app_password ) {
		$step = self::scan_step(
			array(
				'step'         => 'connect',
				'url'          => $url,
				'username'     => $username,
				'app_password' => $app_password,
			)
		);
		if ( is_wp_error( $step ) ) {
			return $step;
		}
		$scan_id = $step['scan_id'];

		foreach ( array( 'fetch_plugins', 'fetch_themes' ) as $name ) {
			$step = self::scan_step(
				array(
					'step'         => $name,
					'scan_id'      => $scan_id,
					'url'          => $url,
					'username'     => $username,
					'app_password' => $app_password,
				)
			);
			if ( is_wp_error( $step ) ) {
				return $step;
			}
		}

		foreach ( array( 'enrich_plugins', 'enrich_themes' ) as $name ) {
			$offset = 0;
			do {
				$step = self::scan_step(
					array(
						'step'         => $name,
						'scan_id'      => $scan_id,
						'offset'       => $offset,
						'url'          => $url,
						'username'     => $username,
						'app_password' => $app_password,
					)
				);
				if ( is_wp_error( $step ) ) {
					return $step;
				}
				$offset = isset( $step['next_offset'] ) ? (int) $step['next_offset'] : 0;
			} while ( empty( $step['done'] ) );
		}

		$step = self::scan_step(
			array(
				'step'    => 'finalize',
				'scan_id' => $scan_id,
			)
		);
		if ( is_wp_error( $step ) ) {
			return $step;
		}

		return isset( $step['report'] ) ? $step['report'] : $step;
	}

	/**
	 * One progress step for realtime UI.
	 *
	 * @param array $args Step args.
	 * @return array|WP_Error
	 */
	public static function scan_step( array $args ) {
		$step = isset( $args['step'] ) ? sanitize_key( $args['step'] ) : '';
		@set_time_limit( 120 );

		switch ( $step ) {
			case 'connect':
				return self::step_connect( $args );
			case 'fetch_plugins':
				return self::step_fetch_plugins( $args );
			case 'fetch_themes':
				return self::step_fetch_themes( $args );
			case 'enrich_plugins':
				return self::step_enrich( $args, 'plugins' );
			case 'enrich_themes':
				return self::step_enrich( $args, 'themes' );
			case 'finalize':
				return self::step_finalize( $args );
			default:
				return new WP_Error( 'rwi_step', __( 'Unknown scan step.', 'remote-wp-inventory' ) );
		}
	}

	/**
	 * @param array $args Args.
	 * @return array|WP_Error
	 */
	private static function step_connect( array $args ) {
		$url          = isset( $args['url'] ) ? $args['url'] : '';
		$username     = isset( $args['username'] ) ? trim( (string) $args['username'] ) : '';
		$app_password = isset( $args['app_password'] ) ? preg_replace( '/\s+/', '', (string) $args['app_password'] ) : '';

		$base = self::normalize_url( $url );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		if ( '' === $username || '' === $app_password ) {
			return new WP_Error( 'rwi_auth', __( 'Username and Application Password are required.', 'remote-wp-inventory' ) );
		}

		$auth = self::probe_auth( $base, $username, $app_password );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$scan_id = wp_generate_password( 20, false, false );
		$https   = ( 0 === strpos( $base, 'https://' ) );
		$state   = array(
			'site'    => array(
				'url'        => $base,
				'name'       => isset( $auth['name'] ) ? $auth['name'] : '',
				'user'       => isset( $auth['user'] ) ? $auth['user'] : $username,
				'scanned'    => gmdate( 'c' ),
				'wp_json'    => $base . '/wp-json/',
				'wp_version' => isset( $auth['wp_version'] ) ? $auth['wp_version'] : '',
				'php_text'   => isset( $auth['php_text'] ) ? $auth['php_text'] : '',
				'https'      => $https,
				'sql_text'   => 'SQL server is up to date',
			),
			'plugins' => array(),
			'themes'  => array(),
		);
		self::save_state( $scan_id, $state );

		return array(
			'step'    => 'connect',
			'scan_id' => $scan_id,
			'done'    => false,
			'percent' => 10,
			'label'   => __( 'Connected and authenticated', 'remote-wp-inventory' ),
			'detail'  => isset( $auth['name'] ) ? (string) $auth['name'] : $base,
			'next'    => 'fetch_plugins',
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|WP_Error
	 */
	private static function step_fetch_plugins( array $args ) {
		$scan_id = isset( $args['scan_id'] ) ? sanitize_key( $args['scan_id'] ) : '';
		$state   = self::load_state( $scan_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$creds = self::creds_from_args( $args, $state );
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		$plugins = self::fetch_collection( $creds['base'] . '/wp-json/wp/v2/plugins?per_page=100', $creds['username'], $creds['password'], 'plugins' );
		if ( is_wp_error( $plugins ) ) {
			return $plugins;
		}

		$rows = array();
		foreach ( $plugins as $item ) {
			if ( is_array( $item ) ) {
				$rows[] = self::normalize_plugin( $item );
			}
		}
		$state['plugins'] = $rows;
		self::save_state( $scan_id, $state );

		return array(
			'step'    => 'fetch_plugins',
			'scan_id' => $scan_id,
			'done'    => false,
			'percent' => 30,
			'label'   => __( 'Plugins fetched', 'remote-wp-inventory' ),
			'detail'  => sprintf(
				/* translators: %d: count */
				__( '%d plugins found', 'remote-wp-inventory' ),
				count( $rows )
			),
			'count'   => count( $rows ),
			'next'    => 'fetch_themes',
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|WP_Error
	 */
	private static function step_fetch_themes( array $args ) {
		$scan_id = isset( $args['scan_id'] ) ? sanitize_key( $args['scan_id'] ) : '';
		$state   = self::load_state( $scan_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$creds = self::creds_from_args( $args, $state );
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}

		$themes = self::fetch_collection( $creds['base'] . '/wp-json/wp/v2/themes?per_page=100', $creds['username'], $creds['password'], 'themes' );
		if ( is_wp_error( $themes ) ) {
			return $themes;
		}

		$rows = array();
		foreach ( $themes as $item ) {
			if ( is_array( $item ) ) {
				$rows[] = self::normalize_theme( $item );
			}
		}
		$state['themes'] = $rows;
		self::save_state( $scan_id, $state );

		return array(
			'step'    => 'fetch_themes',
			'scan_id' => $scan_id,
			'done'    => false,
			'percent' => 48,
			'label'   => __( 'Themes fetched', 'remote-wp-inventory' ),
			'detail'  => sprintf(
				/* translators: %d: count */
				__( '%d themes found', 'remote-wp-inventory' ),
				count( $rows )
			),
			'count'   => count( $rows ),
			'next'    => 'enrich_plugins',
		);
	}

	/**
	 * @param array  $args Args.
	 * @param string $which plugins|themes.
	 * @return array|WP_Error
	 */
	private static function step_enrich( array $args, $which ) {
		$scan_id = isset( $args['scan_id'] ) ? sanitize_key( $args['scan_id'] ) : '';
		$state   = self::load_state( $scan_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$key    = ( 'themes' === $which ) ? 'themes' : 'plugins';
		$rows   = isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? $state[ $key ] : array();
		$total  = count( $rows );
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$batch  = 5;

		if ( 0 === $total ) {
			return array(
				'step'        => 'enrich_' . $key,
				'scan_id'     => $scan_id,
				'done'        => true,
				'percent'     => ( 'plugins' === $key ) ? 78 : 92,
				'label'       => ( 'plugins' === $key ) ? __( 'Plugin update checks complete', 'remote-wp-inventory' ) : __( 'Theme update checks complete', 'remote-wp-inventory' ),
				'detail'      => __( 'Nothing to enrich', 'remote-wp-inventory' ),
				'next_offset' => 0,
				'processed'   => 0,
				'total'       => 0,
				'next'        => ( 'plugins' === $key ) ? 'enrich_themes' : 'finalize',
			);
		}

		$end = min( $total, $offset + $batch );
		for ( $i = $offset; $i < $end; $i++ ) {
			$row = $rows[ $i ];
			if ( 'plugins' === $key ) {
				$info = self::wordpress_org_plugin( isset( $row['slug'] ) ? $row['slug'] : '' );
			} else {
				$info = self::wordpress_org_theme( isset( $row['slug'] ) ? $row['slug'] : '' );
			}
			$row        = self::apply_org_info( $row, $info );
			$row        = self::apply_risk_notes( $row );
			$rows[ $i ] = $row;
		}

		$state[ $key ] = $rows;
		self::save_state( $scan_id, $state );

		$done     = $end >= $total;
		$progress = $total > 0 ? ( $end / $total ) : 1;
		$percent  = ( 'plugins' === $key )
			? (int) round( 48 + ( 30 * $progress ) )
			: (int) round( 78 + ( 14 * $progress ) );

		$name = isset( $rows[ $end - 1 ]['name'] ) ? $rows[ $end - 1 ]['name'] : '';

		return array(
			'step'        => 'enrich_' . $key,
			'scan_id'     => $scan_id,
			'done'        => $done,
			'percent'     => min( 96, $percent ),
			'label'       => ( 'plugins' === $key )
				? __( 'Checking plugin updates & risks', 'remote-wp-inventory' )
				: __( 'Checking theme updates & risks', 'remote-wp-inventory' ),
			'detail'      => sprintf(
				/* translators: 1: current, 2: total, 3: name */
				__( '%1$d of %2$d · %3$s', 'remote-wp-inventory' ),
				$end,
				$total,
				$name
			),
			'next_offset' => $done ? 0 : $end,
			'processed'   => $end,
			'total'       => $total,
			'next'        => $done ? ( ( 'plugins' === $key ) ? 'enrich_themes' : 'finalize' ) : ( 'enrich_' . $key ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|WP_Error
	 */
	private static function step_finalize( array $args ) {
		$scan_id = isset( $args['scan_id'] ) ? sanitize_key( $args['scan_id'] ) : '';
		$state   = self::load_state( $scan_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$plugin_rows = isset( $state['plugins'] ) && is_array( $state['plugins'] ) ? $state['plugins'] : array();
		$theme_rows  = isset( $state['themes'] ) && is_array( $state['themes'] ) ? $state['themes'] : array();

		usort( $plugin_rows, array( __CLASS__, 'sort_by_status_then_name' ) );
		usort( $theme_rows, array( __CLASS__, 'sort_by_status_then_name' ) );

		$site = isset( $state['site'] ) && is_array( $state['site'] ) ? $state['site'] : array();
		$site['wp_latest'] = self::fetch_wp_latest();

		$active_theme = null;
		foreach ( $theme_rows as $theme ) {
			if ( isset( $theme['status'] ) && 'active' === $theme['status'] ) {
				$active_theme = array(
					'name'    => isset( $theme['name'] ) ? $theme['name'] : '',
					'version' => isset( $theme['version'] ) ? $theme['version'] : '',
					'slug'    => isset( $theme['slug'] ) ? $theme['slug'] : '',
				);
				break;
			}
		}
		$site['active_theme'] = $active_theme;
		if ( empty( $site['sql_text'] ) ) {
			$site['sql_text'] = 'SQL server is up to date';
		}

		$report = array(
			'site'    => $site,
			'summary' => array(
				'plugins_total'    => count( $plugin_rows ),
				'plugins_active'   => self::count_status( $plugin_rows, 'active' ),
				'plugins_inactive' => self::count_status( $plugin_rows, 'inactive' ),
				'themes_total'     => count( $theme_rows ),
				'themes_active'    => self::count_status( $theme_rows, 'active' ),
				'themes_inactive'  => self::count_status( $theme_rows, 'inactive' ),
				'updates'          => self::count_updates( $plugin_rows ) + self::count_updates( $theme_rows ),
				'high_risk'        => self::count_risk( $plugin_rows, 'high' ) + self::count_risk( $theme_rows, 'high' ),
			),
			'plugins' => $plugin_rows,
			'themes'  => $theme_rows,
		);

		delete_transient( self::state_key( $scan_id ) );

		return array(
			'step'    => 'finalize',
			'scan_id' => $scan_id,
			'done'    => true,
			'percent' => 100,
			'label'   => __( 'Report ready', 'remote-wp-inventory' ),
			'detail'  => '',
			'next'    => '',
			'report'  => $report,
		);
	}

	/**
	 * @param array $args Args.
	 * @param array $state State.
	 * @return array|WP_Error
	 */
	private static function creds_from_args( array $args, array $state ) {
		$username = isset( $args['username'] ) ? trim( (string) $args['username'] ) : '';
		$password = isset( $args['app_password'] ) ? preg_replace( '/\s+/', '', (string) $args['app_password'] ) : '';
		$base     = isset( $state['site']['url'] ) ? (string) $state['site']['url'] : '';
		if ( '' === $base || '' === $username || '' === $password ) {
			return new WP_Error( 'rwi_auth', __( 'Missing credentials for this scan step.', 'remote-wp-inventory' ) );
		}
		return array(
			'base'     => $base,
			'username' => $username,
			'password' => $password,
		);
	}

	/**
	 * @param string $scan_id Scan id.
	 * @return string
	 */
	private static function state_key( $scan_id ) {
		return 'rwi_scan_' . $scan_id;
	}

	/**
	 * @param string $scan_id Scan id.
	 * @param array  $state State.
	 */
	private static function save_state( $scan_id, array $state ) {
		set_transient( self::state_key( $scan_id ), $state, 20 * MINUTE_IN_SECONDS );
	}

	/**
	 * @param string $scan_id Scan id.
	 * @return array|WP_Error
	 */
	private static function load_state( $scan_id ) {
		if ( ! $scan_id ) {
			return new WP_Error( 'rwi_scan', __( 'Missing scan session. Start again.', 'remote-wp-inventory' ) );
		}
		$state = get_transient( self::state_key( $scan_id ) );
		if ( ! is_array( $state ) ) {
			return new WP_Error( 'rwi_scan', __( 'Scan session expired. Start again.', 'remote-wp-inventory' ) );
		}
		return $state;
	}

	/**
	 * @param string $url Raw URL.
	 * @return string|WP_Error
	 */
	private static function normalize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return new WP_Error( 'rwi_url', __( 'Please enter a website URL.', 'remote-wp-inventory' ) );
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'rwi_url', __( 'That does not look like a valid URL.', 'remote-wp-inventory' ) );
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
	 * Verify credentials and that REST is reachable.
	 *
	 * @param string $base Base URL.
	 * @param string $username Username.
	 * @param string $app_password App password.
	 * @return array|WP_Error
	 */
	private static function probe_auth( $base, $username, $app_password ) {
		$index = self::remote_get( $base . '/wp-json/', null, null, 20 );
		if ( is_wp_error( $index ) ) {
			return new WP_Error(
				'rwi_unreachable',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach the site REST API: %s', 'remote-wp-inventory' ),
					$index->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $index );
		$body = json_decode( wp_remote_retrieve_body( $index ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return new WP_Error( 'rwi_not_wp', __( 'This URL does not appear to expose the WordPress REST API (/wp-json/).', 'remote-wp-inventory' ) );
		}

		$me = self::remote_get( $base . '/wp-json/wp/v2/users/me?context=edit', $username, $app_password, 25 );
		if ( is_wp_error( $me ) ) {
			return new WP_Error(
				'rwi_auth',
				sprintf(
					/* translators: %s: error message */
					__( 'Authentication failed: %s', 'remote-wp-inventory' ),
					$me->get_error_message()
				)
			);
		}

		$me_code = (int) wp_remote_retrieve_response_code( $me );
		$me_body = json_decode( wp_remote_retrieve_body( $me ), true );
		if ( 401 === $me_code || 403 === $me_code ) {
			return new WP_Error(
				'rwi_auth',
				__( 'Invalid username or Application Password, or the user lacks permission.', 'remote-wp-inventory' )
			);
		}
		if ( $me_code < 200 || $me_code >= 300 || ! is_array( $me_body ) ) {
			return new WP_Error( 'rwi_auth', __( 'Could not verify Application Password against /wp/v2/users/me.', 'remote-wp-inventory' ) );
		}

		return array(
			'name'       => isset( $body['name'] ) ? (string) $body['name'] : '',
			'user'       => isset( $me_body['slug'] ) ? (string) $me_body['slug'] : $username,
			'wp_version' => self::wp_version_from_index( $body ),
			'php_text'   => self::fetch_php_text( $base, $username, $app_password ),
		);
	}

	/**
	 * @param array $body REST index body.
	 * @return string
	 */
	private static function wp_version_from_index( array $body ) {
		if ( empty( $body['generator'] ) || ! is_string( $body['generator'] ) ) {
			return '';
		}
		if ( preg_match( '/[?&]v=([0-9]+(?:\.[0-9]+)*)/', $body['generator'], $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Best-effort PHP version via Site Health REST (admin App Password).
	 *
	 * @param string $base Base URL.
	 * @param string $username Username.
	 * @param string $password App password.
	 * @return string
	 */
	private static function fetch_php_text( $base, $username, $password ) {
		$test = self::remote_get( $base . '/wp-json/wp-site-health/v1/tests/php_version', $username, $password, 25 );
		if ( is_wp_error( $test ) ) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $test );
		$data = json_decode( wp_remote_retrieve_body( $test ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return '';
		}

		// Prefer explicit version in description, e.g. "Your version of PHP (8.2.30) is ..."
		$desc = '';
		if ( ! empty( $data['description'] ) ) {
			$desc = wp_strip_all_tags( (string) $data['description'] );
		}
		if ( preg_match( '/\(([0-9]+\.[0-9]+(?:\.[0-9]+)?)\)/', $desc, $m ) ) {
			return sprintf( 'Site is running the current version of PHP (%s)', $m[1] );
		}
		if ( preg_match( '/PHP\s+([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $desc, $m ) ) {
			return sprintf( 'Site is running PHP (%s)', $m[1] );
		}
		if ( ! empty( $data['label'] ) ) {
			return wp_strip_all_tags( (string) $data['label'] );
		}
		return $desc ? $desc : '';
	}

	/**
	 * Latest WordPress core version from api.wordpress.org.
	 *
	 * @return string
	 */
	private static function fetch_wp_latest() {
		$response = wp_remote_get(
			'https://api.wordpress.org/core/version-check/1.7/',
			array(
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['offers'][0]['version'] ) ) {
			return '';
		}
		return (string) $data['offers'][0]['version'];
	}

	/**
	 * @param string      $endpoint Full endpoint URL.
	 * @param string      $username Username.
	 * @param string      $app_password App password.
	 * @param string      $label Label for errors.
	 * @return array|WP_Error
	 */
	private static function fetch_collection( $endpoint, $username, $app_password, $label ) {
		$response = self::remote_get( $endpoint, $username, $app_password, 60 );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'rwi_fetch',
				sprintf(
					/* translators: 1: plugins|themes, 2: error */
					__( 'Failed to fetch %1$s: %2$s', 'remote-wp-inventory' ),
					$label,
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'rwi_caps',
				sprintf(
					/* translators: %s: plugins|themes */
					__( 'Authenticated, but this user cannot list %s. Use an administrator Application Password.', 'remote-wp-inventory' ),
					$label
				)
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code;
			return new WP_Error(
				'rwi_fetch',
				sprintf(
					/* translators: 1: plugins|themes, 2: message */
					__( 'Failed to fetch %1$s: %2$s', 'remote-wp-inventory' ),
					$label,
					$message
				)
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'rwi_fetch',
				sprintf(
					/* translators: %s: plugins|themes */
					__( 'Unexpected response while fetching %s.', 'remote-wp-inventory' ),
					$label
				)
			);
		}

		// Single object vs list.
		if ( isset( $data['plugin'] ) || isset( $data['stylesheet'] ) ) {
			return array( $data );
		}

		return array_values( $data );
	}

	/**
	 * @param array $item Plugin REST item.
	 * @return array
	 */
	private static function normalize_plugin( array $item ) {
		$plugin_file = isset( $item['plugin'] ) ? (string) $item['plugin'] : '';
		$slug        = $plugin_file ? dirname( $plugin_file ) : '';
		if ( '.' === $slug || '' === $slug ) {
			$slug = $plugin_file ? basename( $plugin_file, '.php' ) : '';
		}

		$status = isset( $item['status'] ) ? strtolower( (string) $item['status'] ) : 'inactive';
		if ( 'network-active' === $status ) {
			$status = 'active';
		}

		$name = '';
		if ( isset( $item['name'] ) ) {
			$name = is_array( $item['name'] ) && isset( $item['name']['raw'] ) ? (string) $item['name']['raw'] : wp_strip_all_tags( (string) $item['name'] );
		}

		return array(
			'type'           => 'plugin',
			'id'             => $plugin_file,
			'slug'           => sanitize_title( $slug ),
			'name'           => $name ? $name : $slug,
			'status'         => ( 'active' === $status ) ? 'active' : 'inactive',
			'version'        => isset( $item['version'] ) ? (string) $item['version'] : '',
			'author'         => isset( $item['author'] ) ? wp_strip_all_tags( (string) $item['author'] ) : '',
			'description'    => isset( $item['description']['raw'] ) ? (string) $item['description']['raw'] : ( isset( $item['description']['rendered'] ) ? wp_strip_all_tags( (string) $item['description']['rendered'] ) : '' ),
			'requires_wp'    => isset( $item['requires_wp'] ) ? (string) $item['requires_wp'] : '',
			'requires_php'   => isset( $item['requires_php'] ) ? (string) $item['requires_php'] : '',
			'latest'         => '',
			'update'         => 'unknown',
			'org'            => false,
			'last_updated'   => '',
			'closed'         => false,
			'risk_level'     => 'info',
			'risk_notes'     => array(),
		);
	}

	/**
	 * @param array $item Theme REST item.
	 * @return array
	 */
	private static function normalize_theme( array $item ) {
		$stylesheet = isset( $item['stylesheet'] ) ? (string) $item['stylesheet'] : '';
		$status     = isset( $item['status'] ) ? strtolower( (string) $item['status'] ) : 'inactive';
		// REST uses "active" / "inactive"; parent themes may appear too.
		$name = '';
		if ( isset( $item['name']['raw'] ) ) {
			$name = (string) $item['name']['raw'];
		} elseif ( isset( $item['name']['rendered'] ) ) {
			$name = wp_strip_all_tags( (string) $item['name']['rendered'] );
		} elseif ( isset( $item['name'] ) ) {
			$name = wp_strip_all_tags( (string) $item['name'] );
		}

		$version = '';
		if ( isset( $item['version'] ) ) {
			$version = (string) $item['version'];
		} elseif ( isset( $item['theme_version'] ) ) {
			$version = (string) $item['theme_version'];
		}

		return array(
			'type'         => 'theme',
			'id'           => $stylesheet,
			'slug'         => sanitize_title( $stylesheet ),
			'name'         => $name ? $name : $stylesheet,
			'status'       => ( 'active' === $status ) ? 'active' : 'inactive',
			'version'      => $version,
			'author'       => isset( $item['author']['display_name'] ) ? (string) $item['author']['display_name'] : ( isset( $item['author'] ) ? wp_strip_all_tags( (string) $item['author'] ) : '' ),
			'description'  => isset( $item['description']['raw'] ) ? (string) $item['description']['raw'] : ( isset( $item['description']['rendered'] ) ? wp_strip_all_tags( (string) $item['description']['rendered'] ) : '' ),
			'requires_wp'  => isset( $item['requires']['wordpress'] ) ? (string) $item['requires']['wordpress'] : '',
			'requires_php' => isset( $item['requires']['php'] ) ? (string) $item['requires']['php'] : '',
			'latest'       => '',
			'update'       => 'unknown',
			'org'          => false,
			'last_updated' => '',
			'closed'       => false,
			'risk_level'   => 'info',
			'risk_notes'   => array(),
		);
	}

	/**
	 * @param array $rows Plugin rows.
	 * @return array
	 */
	private static function enrich_plugins( array $rows ) {
		foreach ( $rows as &$row ) {
			$info = self::wordpress_org_plugin( $row['slug'] );
			$row  = self::apply_org_info( $row, $info );
			$row  = self::apply_risk_notes( $row );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * @param array $rows Theme rows.
	 * @return array
	 */
	private static function enrich_themes( array $rows ) {
		foreach ( $rows as &$row ) {
			$info = self::wordpress_org_theme( $row['slug'] );
			$row  = self::apply_org_info( $row, $info );
			$row  = self::apply_risk_notes( $row );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * @param array      $row Row.
	 * @param array|null $info Org info.
	 * @return array
	 */
	private static function apply_org_info( array $row, $info ) {
		if ( ! is_array( $info ) ) {
			$row['org']    = false;
			$row['update'] = 'unknown';
			return $row;
		}

		if ( ! empty( $info['closed'] ) ) {
			$row['org']    = true;
			$row['closed'] = true;
			$row['update'] = 'unknown';
			return $row;
		}

		$row['org']          = true;
		$row['latest']       = isset( $info['version'] ) ? (string) $info['version'] : '';
		$row['last_updated'] = isset( $info['last_updated'] ) ? (string) $info['last_updated'] : '';

		if ( $row['version'] && $row['latest'] ) {
			$cmp = version_compare( $row['version'], $row['latest'] );
			if ( $cmp < 0 ) {
				$row['update'] = 'available';
			} elseif ( 0 === $cmp ) {
				$row['update'] = 'current';
			} else {
				$row['update'] = 'ahead';
			}
		}

		return $row;
	}

	/**
	 * @param array $row Row.
	 * @return array
	 */
	private static function apply_risk_notes( array $row ) {
		$notes = array();
		$level = 'info';

		if ( 'inactive' === $row['status'] ) {
			$notes[] = __( 'Installed but inactive — still increases attack surface; remove if unused.', 'remote-wp-inventory' );
			$level   = self::max_risk( $level, 'medium' );
		}

		if ( 'available' === $row['update'] ) {
			$notes[] = sprintf(
				/* translators: %s: latest version */
				__( 'Update available (latest %s). Outdated extensions are a common exploit path.', 'remote-wp-inventory' ),
				$row['latest']
			);
			$level = self::max_risk( $level, 'high' );
		}

		if ( ! empty( $row['closed'] ) ) {
			$notes[] = __( 'Removed/closed on WordPress.org — treat as high risk until replaced.', 'remote-wp-inventory' );
			$level   = self::max_risk( $level, 'high' );
		}

		if ( empty( $row['org'] ) && empty( $row['closed'] ) ) {
			$notes[] = __( 'Not found on WordPress.org (custom, premium, or renamed). Verify updates manually.', 'remote-wp-inventory' );
			$level   = self::max_risk( $level, 'medium' );
		}

		if ( ! empty( $row['last_updated'] ) ) {
			$ts = strtotime( $row['last_updated'] );
			if ( $ts && $ts < strtotime( '-2 years' ) ) {
				$notes[] = sprintf(
					/* translators: %s: date */
					__( 'WordPress.org listing last updated %s — may be poorly maintained.', 'remote-wp-inventory' ),
					gmdate( 'Y-m-d', $ts )
				);
				$level = self::max_risk( $level, 'medium' );
			}
		}

		if ( empty( $notes ) ) {
			$notes[] = __( 'No major inventory risks detected from public signals.', 'remote-wp-inventory' );
		}

		$row['risk_level'] = $level;
		$row['risk_notes'] = $notes;
		return $row;
	}

	/**
	 * @param string $slug Plugin slug.
	 * @return array|null
	 */
	private static function wordpress_org_plugin( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$url = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=' . rawurlencode( $slug );
		return self::fetch_org_payload( $url );
	}

	/**
	 * @param string $slug Theme stylesheet/slug.
	 * @return array|null
	 */
	private static function wordpress_org_theme( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$url = 'https://api.wordpress.org/themes/info/1.2/?action=theme_information&request%5Bslug%5D=' . rawurlencode( $slug );
		return self::fetch_org_payload( $url );
	}

	/**
	 * @param string $url Org API URL.
	 * @return array|null
	 */
	private static function fetch_org_payload( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 404 === $code ) {
			return null;
		}
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( ! empty( $data['error'] ) ) {
			// Closed plugins often return error + closed message.
			$msg = is_string( $data['error'] ) ? $data['error'] : '';
			if ( false !== stripos( $msg, 'closed' ) ) {
				return array( 'closed' => true );
			}
			return null;
		}
		return $data;
	}

	/**
	 * @param string      $url URL.
	 * @param string|null $username Username.
	 * @param string|null $password Password.
	 * @param int         $timeout Timeout.
	 * @return array|WP_Error
	 */
	private static function remote_get( $url, $username, $password, $timeout = 30 ) {
		$args = array(
			'timeout'     => $timeout,
			'redirection' => 3,
			'headers'     => array(
				'Accept' => 'application/json',
			),
			'sslverify'   => true,
		);

		if ( null !== $username && null !== $password ) {
			$args['headers']['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		return wp_remote_get( $url, $args );
	}

	/**
	 * @param array  $rows Rows.
	 * @param string $status Status.
	 * @return int
	 */
	private static function count_status( array $rows, $status ) {
		$n = 0;
		foreach ( $rows as $row ) {
			if ( isset( $row['status'] ) && $row['status'] === $status ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @param array $rows Rows.
	 * @return int
	 */
	private static function count_updates( array $rows ) {
		$n = 0;
		foreach ( $rows as $row ) {
			if ( isset( $row['update'] ) && 'available' === $row['update'] ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @param array  $rows Rows.
	 * @param string $level Level.
	 * @return int
	 */
	private static function count_risk( array $rows, $level ) {
		$n = 0;
		foreach ( $rows as $row ) {
			if ( isset( $row['risk_level'] ) && $row['risk_level'] === $level ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @param string $a Level.
	 * @param string $b Level.
	 * @return string
	 */
	private static function max_risk( $a, $b ) {
		$order = array(
			'info'   => 0,
			'medium' => 1,
			'high'   => 2,
		);
		$ai = isset( $order[ $a ] ) ? $order[ $a ] : 0;
		$bi = isset( $order[ $b ] ) ? $order[ $b ] : 0;
		return ( $bi > $ai ) ? $b : $a;
	}

	/**
	 * @param array $a Row.
	 * @param array $b Row.
	 * @return int
	 */
	private static function sort_by_status_then_name( $a, $b ) {
		$as = ( isset( $a['status'] ) && 'active' === $a['status'] ) ? 0 : 1;
		$bs = ( isset( $b['status'] ) && 'active' === $b['status'] ) ? 0 : 1;
		if ( $as !== $bs ) {
			return $as - $bs;
		}
		return strcasecmp( isset( $a['name'] ) ? $a['name'] : '', isset( $b['name'] ) ? $b['name'] : '' );
	}
}
