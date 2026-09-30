<?php
/**
 * Category 3: Security & Monitoring checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_Security {

	/**
	 * @return array[]
	 */
	public static function run() {
		if ( class_exists( 'YWA_Remote_Client' ) && YWA_Remote_Client::available() ) {
			$remote = self::run_remote();
			if ( ! is_wp_error( $remote ) ) {
				return $remote;
			}
			// Surface remote failure as a clear fail rather than silently auditing the local site.
			$labels = YWA_Categories::get( 'security' )['checks'];
			return array(
				YWA_Helpers::result(
					'authentication',
					$labels['authentication'],
					'fail',
					$remote->get_error_message(),
					array( 'Remote security checks could not run; local site was not used.' ),
					1.2
				),
			);
		}

		return self::run_local();
	}

	/**
	 * Security checks against the selected remote WordPress site.
	 *
	 * @return array[]|WP_Error
	 */
	private static function run_remote() {
		$snap = YWA_Remote_Client::security_snapshot();
		if ( is_wp_error( $snap ) ) {
			return $snap;
		}

		$labels  = YWA_Categories::get( 'security' )['checks'];
		$results = array();

		$admins = isset( $snap['admins'] ) ? (int) $snap['admins'] : 0;
		$auth_ev = array( 'Administrator accounts: ' . $admins );
		$auth_status = 'pass';
		if ( empty( $snap['users_fetched'] ) ) {
			$auth_status = 'warn';
			$auth_ev[]   = 'Could not list remote users (insufficient caps); admin count may be incomplete.';
		}
		if ( $admins > 5 ) {
			$auth_status = 'warn';
			$auth_ev[]   = 'High number of administrators';
		}
		if ( ! empty( $snap['has_admin_username'] ) ) {
			$auth_status = 'fail';
			$auth_ev[]   = 'Default "admin" username exists';
		}
		$results[] = YWA_Helpers::result( 'authentication', $labels['authentication'], $auth_status, 'Reviewed administrator accounts and default usernames on the selected site.', $auth_ev, 1.2 );

		// RBAC is not fully exposed over REST — do not invent local role data.
		$results[] = YWA_Helpers::result(
			'rbac',
			$labels['rbac'],
			'skip',
			'Role capability matrix cannot be fully verified remotely via REST; skipped for remote targets.',
			array(),
			1.2
		);

		$sec_plugins = array(
			'all-in-one-wp-security',
			'better-wp-security',
			'wordfence',
			'sucuri',
			'ithemes-security',
			'bulletproof-security',
			'defender-security',
			'solid-security',
		);
		$has_sec = YWA_Remote_Client::has_active_plugin( $snap, $sec_plugins );
		$results[] = YWA_Helpers::result(
			'security_plugins',
			$labels['security_plugins'],
			$has_sec ? 'pass' : 'fail',
			$has_sec ? 'Security plugin detected on the selected site (e.g. AIOS / Wordfence / similar).' : 'No common security plugin active on the selected site.',
			array(),
			1.4
		);

		$is_ssl = ! empty( $snap['is_https'] );
		$results[] = YWA_Helpers::result(
			'encryption',
			$labels['encryption'],
			$is_ssl ? 'pass' : 'fail',
			$is_ssl ? 'Selected site URL uses HTTPS.' : 'Selected site is not served over HTTPS.',
			array( 'Remote FORCE_SSL_ADMIN: not available via REST' ),
			1.4
		);

		$results[] = YWA_Helpers::result(
			'secrets',
			$labels['secrets'],
			'skip',
			'wp-config secrets / DISALLOW_FILE_EDIT cannot be read remotely; skipped for remote targets.',
			array(),
			1.3
		);

		$log_plugins = array( 'wp-security-audit-log', 'activity-log', 'aryo-activity-log', 'stream', 'simple-history', 'wp-activity-log' );
		$has_logs    = YWA_Remote_Client::has_active_plugin( $snap, $log_plugins );
		$results[]   = YWA_Helpers::result(
			'audit_logs',
			$labels['audit_logs'],
			$has_logs ? 'pass' : 'fail',
			$has_logs ? 'Activity / audit logging plugin detected on the selected site.' : 'No audit logging plugin detected on the selected site.',
			array(),
			1.3
		);
		$results[] = YWA_Helpers::result(
			'activity_monitoring',
			$labels['activity_monitoring'],
			$has_logs ? 'pass' : 'warn',
			$has_logs ? 'Activity monitoring appears available via logging plugin on the selected site.' : 'Activity monitoring not clearly implemented on the selected site.',
			array(),
			1.1
		);

		$login_plugins = array( 'limit-login', 'loginizer', 'wordfence', 'all-in-one-wp-security', 'better-wp-security', 'solid-security', 'wps-hide-login', 'rename-wp-login' );
		$has_login     = YWA_Remote_Client::has_active_plugin( $snap, $login_plugins );
		$results[]     = YWA_Helpers::result(
			'login_security',
			$labels['login_security'],
			$has_login ? 'pass' : 'warn',
			$has_login ? 'Login hardening plugin/controls detected on the selected site.' : 'No dedicated login rate-limiting / hide-login controls detected on the selected site.',
			array(),
			1.2
		);

		$update_count = isset( $snap['plugin_updates'] ) ? (int) $snap['plugin_updates'] : 0;
		$vuln_status  = 'pass';
		if ( $update_count > 5 ) {
			$vuln_status = 'fail';
		} elseif ( $update_count > 0 ) {
			$vuln_status = 'warn';
		}
		$results[] = YWA_Helpers::result(
			'vulnerabilities',
			$labels['vulnerabilities'],
			$vuln_status,
			sprintf( 'Plugins needing updates on selected site: %d. Core upgrade check: not available via REST.', $update_count ),
			array(),
			1.3
		);

		return $results;
	}

	/**
	 * Security checks against the local WordPress install (wp-admin audits).
	 *
	 * @return array[]
	 */
	private static function run_local() {
		$labels  = YWA_Categories::get( 'security' )['checks'];
		$results = array();

		$users  = count_users();
		$admins = isset( $users['avail_roles']['administrator'] ) ? (int) $users['avail_roles']['administrator'] : 0;
		$auth_ev = array( 'Administrator accounts: ' . $admins );
		$auth_status = 'pass';
		if ( $admins > 5 ) {
			$auth_status = 'warn';
			$auth_ev[]   = 'High number of administrators';
		}
		if ( username_exists( 'admin' ) ) {
			$auth_status = 'fail';
			$auth_ev[]   = 'Default "admin" username exists';
		}
		$results[] = YWA_Helpers::result( 'authentication', $labels['authentication'], $auth_status, 'Reviewed administrator accounts and default usernames.', $auth_ev, 1.2 );

		$roles = wp_roles();
		$role_count = is_object( $roles ) ? count( $roles->roles ) : 0;
		$editor = get_role( 'editor' );
		$can_plugins = $editor && $editor->has_cap( 'install_plugins' );
		$results[] = YWA_Helpers::result(
			'rbac',
			$labels['rbac'],
			$can_plugins ? 'fail' : ( $role_count >= 3 ? 'pass' : 'warn' ),
			$can_plugins
				? 'Editor role can install plugins — privilege escalation risk.'
				: sprintf( 'Role system present with %d roles; editor cannot install plugins.', $role_count ),
			array(),
			1.2
		);

		$sec_plugins = array(
			'all-in-one-wp-security',
			'better-wp-security',
			'wordfence',
			'sucuri',
			'ithemes-security',
			'bulletproof-security',
			'defender-security',
			'solid-security',
		);
		$has_sec = YWA_Helpers::has_active_plugin( $sec_plugins );
		$results[] = YWA_Helpers::result(
			'security_plugins',
			$labels['security_plugins'],
			$has_sec ? 'pass' : 'fail',
			$has_sec ? 'Security plugin detected (e.g. AIOS / Wordfence / similar).' : 'No common security plugin active.',
			array(),
			1.4
		);

		$is_ssl = is_ssl() || ( 0 === strpos( home_url(), 'https://' ) );
		$force  = force_ssl_admin();
		$results[] = YWA_Helpers::result(
			'encryption',
			$labels['encryption'],
			$is_ssl ? 'pass' : 'fail',
			$is_ssl ? 'Site URL uses HTTPS.' : 'Site is not served over HTTPS.',
			array( 'FORCE_SSL_ADMIN: ' . ( $force ? 'true' : 'false' ) ),
			1.4
		);

		$secret_issues = array();
		if ( defined( 'AUTH_KEY' ) && false !== strpos( AUTH_KEY, 'put your unique phrase' ) ) {
			$secret_issues[] = 'Default AUTH_KEY salts still present';
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && ! DISALLOW_FILE_EDIT ) {
			$secret_issues[] = 'DISALLOW_FILE_EDIT is not enabled';
		} elseif ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			$secret_issues[] = 'DISALLOW_FILE_EDIT not defined';
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ) {
			$secret_issues[] = 'WP_DEBUG display may expose errors';
		}
		$config = ABSPATH . 'wp-config.php';
		if ( is_readable( $config ) ) {
			$cfg = YWA_Helpers::read_file( $config, 50000 );
			if ( preg_match( '/define\s*\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"][\'"]\s*\)/', $cfg ) ) {
				$secret_issues[] = 'Empty DB_PASSWORD in wp-config';
			}
		}
		$results[] = YWA_Helpers::result(
			'secrets',
			$labels['secrets'],
			empty( $secret_issues ) ? 'pass' : ( count( $secret_issues ) > 2 ? 'fail' : 'warn' ),
			empty( $secret_issues ) ? 'Secrets / debug hardening looks reasonable.' : 'Secrets or hardening gaps found.',
			$secret_issues,
			1.3
		);

		$log_plugins = array( 'wp-security-audit-log', 'activity-log', 'aryo-activity-log', 'stream', 'simple-history', 'wp-activity-log' );
		$has_logs = YWA_Helpers::has_active_plugin( $log_plugins );
		$results[] = YWA_Helpers::result(
			'audit_logs',
			$labels['audit_logs'],
			$has_logs ? 'pass' : 'fail',
			$has_logs ? 'Activity / audit logging plugin detected.' : 'No audit logging plugin (e.g. WP Activity Log) detected.',
			array(),
			1.3
		);
		$results[] = YWA_Helpers::result(
			'activity_monitoring',
			$labels['activity_monitoring'],
			$has_logs ? 'pass' : 'warn',
			$has_logs ? 'Activity monitoring appears available via logging plugin.' : 'Activity monitoring not clearly implemented.',
			array(),
			1.1
		);

		$login_plugins = array( 'limit-login', 'loginizer', 'wordfence', 'all-in-one-wp-security', 'better-wp-security', 'solid-security', 'wps-hide-login', 'rename-wp-login' );
		$has_login = YWA_Helpers::has_active_plugin( $login_plugins );
		$xmlrpc    = file_exists( ABSPATH . 'xmlrpc.php' );
		$login_ev  = array( 'xmlrpc.php present: ' . ( $xmlrpc ? 'yes' : 'no' ) );
		$results[] = YWA_Helpers::result(
			'login_security',
			$labels['login_security'],
			$has_login ? 'pass' : 'warn',
			$has_login ? 'Login hardening plugin/controls detected.' : 'No dedicated login rate-limiting / hide-login controls detected.',
			$login_ev,
			1.2
		);

		if ( ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			include_once ABSPATH . 'wp-admin/includes/update.php';
		}
		wp_update_plugins();
		$updates = get_plugin_updates();
		$update_count = is_array( $updates ) ? count( $updates ) : 0;
		$core_updates = get_core_updates();
		$core_needed  = false;
		if ( is_array( $core_updates ) ) {
			foreach ( $core_updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response ) {
					$core_needed = true;
					break;
				}
			}
		}
		$vuln_status = 'pass';
		if ( $update_count > 5 || $core_needed ) {
			$vuln_status = 'fail';
		} elseif ( $update_count > 0 ) {
			$vuln_status = 'warn';
		}
		$results[] = YWA_Helpers::result(
			'vulnerabilities',
			$labels['vulnerabilities'],
			$vuln_status,
			sprintf( 'Plugins needing updates: %d. Core upgrade available: %s.', $update_count, $core_needed ? 'yes' : 'no' ),
			array(),
			1.3
		);

		return $results;
	}
}
