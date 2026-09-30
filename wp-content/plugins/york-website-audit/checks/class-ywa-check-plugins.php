<?php
/**
 * Category 4: Plugin & Platform Governance checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_Plugins {

	/**
	 * @return array[]
	 */
	public static function run() {
		$labels  = YWA_Categories::get( 'plugin_governance' )['checks'];
		$results = array();

		if ( YWA_Helpers::is_remote_audit() ) {
			return self::run_remote( $labels );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			include_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$all      = YWA_Helpers::get_plugins();
		$active   = (array) get_option( 'active_plugins', array() );
		$total    = count( $all );
		$active_n = count( $active );
		$inactive = max( 0, $total - $active_n );

		$inactive_names = array();
		foreach ( $all as $file => $data ) {
			if ( in_array( $file, $active, true ) ) {
				continue;
			}
			$inactive_names[] = isset( $data['Name'] ) && $data['Name'] ? $data['Name'] : $file;
		}
		sort( $inactive_names, SORT_NATURAL | SORT_FLAG_CASE );

		$results[] = YWA_Helpers::result(
			'installed_plugins',
			$labels['installed_plugins'],
			$total <= 30 ? 'pass' : ( $total <= 45 ? 'warn' : 'fail' ),
			sprintf( '%d plugins installed (%d active).', $total, $active_n ),
			array(),
			1.2
		);

		$updates = get_plugin_updates();
		$update_n = is_array( $updates ) ? count( $updates ) : 0;
		$update_list = array();
		if ( is_array( $updates ) ) {
			foreach ( array_slice( $updates, 0, 10, true ) as $file => $data ) {
				$name = isset( $data->Name ) ? $data->Name : $file;
				$update_list[] = $name;
			}
		}
		$results[] = YWA_Helpers::result(
			'plugin_updates',
			$labels['plugin_updates'],
			0 === $update_n ? 'pass' : ( $update_n <= 3 ? 'warn' : 'fail' ),
			sprintf( '%d plugin(s) have available updates.', $update_n ),
			$update_list,
			1.4
		);

		$results[] = YWA_Helpers::result(
			'unused_plugins',
			$labels['unused_plugins'],
			0 === $inactive ? 'pass' : ( $inactive <= 3 ? 'warn' : 'fail' ),
			sprintf( '%d inactive plugin(s) installed.', $inactive ),
			$inactive_names,
			1.3
		);

		// Licensing — detect common premium plugins without checking remote license APIs deeply.
		$license_ev = array();
		foreach ( $all as $file => $data ) {
			$name = isset( $data['Name'] ) ? $data['Name'] : $file;
			if ( preg_match( '/acf|gravity|wpml|elementor pro|divi|yoast premium|rank math pro/i', $name . ' ' . $file ) ) {
				$license_ev[] = $name;
			}
		}
		// Check a few common license option patterns.
		$license_options = array( 'acf_pro_license', 'gravityforms_license_key', 'mc4wp_license_key' );
		foreach ( $license_options as $opt ) {
			$val = get_option( $opt );
			if ( ! empty( $val ) ) {
				$license_ev[] = 'Option present: ' . $opt;
			}
		}
		$results[] = YWA_Helpers::result(
			'licensing',
			$labels['licensing'],
			'warn',
			'Premium plugins detected should be verified for valid licenses manually.',
			array_slice( $license_ev, 0, 10 ),
			0.9
		);

		// Plugin documentation in theme / project.
		$doc_paths = array(
			YWA_Helpers::theme_dir() . '/README.md',
			YWA_Helpers::theme_dir() . '/readme.md',
			YWA_Helpers::theme_dir() . '/docs',
			ABSPATH . 'README.md',
			WP_CONTENT_DIR . '/plugins-readme.md',
		);
		$doc_found = array();
		foreach ( $doc_paths as $p ) {
			if ( file_exists( $p ) ) {
				$doc_found[] = YWA_Helpers::rel_path( $p );
			}
		}
		$results[] = YWA_Helpers::result(
			'plugin_docs',
			$labels['plugin_docs'],
			! empty( $doc_found ) ? 'pass' : 'warn',
			! empty( $doc_found ) ? 'Project documentation files found.' : 'No plugin/project documentation inventory found.',
			$doc_found,
			1.0
		);

		// Third-party services in homepage.
		$html = YWA_Helpers::homepage_html();
		$services = array();
		$map = array(
			'Google Analytics' => 'google-analytics|gtag/js|googletagmanager',
			'Google Tag Manager' => 'googletagmanager.com/gtm',
			'Hotjar' => 'static.hotjar.com',
			'HubSpot' => 'js.hs-scripts.com|hs-analytics',
			'Intercom' => 'widget.intercom.io',
			'Cloudflare' => 'cdnjs.cloudflare|cloudflareinsights',
			'Stripe' => 'js.stripe.com',
		);
		foreach ( $map as $name => $pattern ) {
			if ( preg_match( '/' . $pattern . '/i', $html ) ) {
				$services[] = $name;
			}
		}
		$results[] = YWA_Helpers::result(
			'third_party',
			$labels['third_party'],
			count( $services ) <= 6 ? 'pass' : 'warn',
			sprintf( '%d third-party front-end services detected on homepage.', count( $services ) ),
			$services,
			1.0
		);

		// Required plugins: WP Activity Log, AIOS.
		$has_activity = YWA_Helpers::has_active_plugin( array( 'wp-security-audit-log', 'activity-log', 'wp-activity-log', 'simple-history', 'stream' ) );
		$has_aios     = YWA_Helpers::has_active_plugin( array( 'all-in-one-wp-security', 'better-wp-security', 'solid-security' ) );
		$req_status   = ( $has_activity && $has_aios ) ? 'pass' : ( ( $has_activity || $has_aios ) ? 'warn' : 'fail' );
		$results[] = YWA_Helpers::result(
			'required_plugins',
			$labels['required_plugins'],
			$req_status,
			sprintf( 'WP Activity Log (or equivalent): %s · AIOS (or equivalent): %s', $has_activity ? 'yes' : 'no', $has_aios ? 'yes' : 'no' ),
			array(),
			1.4
		);

		return $results;
	}

	/**
	 * Plugin governance against the selected remote site.
	 *
	 * @param array $labels Check labels.
	 * @return array[]
	 */
	private static function run_remote( array $labels ) {
		$snap = YWA_Remote_Client::security_snapshot();
		if ( is_wp_error( $snap ) ) {
			return array(
				YWA_Helpers::result(
					'installed_plugins',
					$labels['installed_plugins'],
					'fail',
					$snap->get_error_message(),
					array(),
					1.2
				),
			);
		}

		$plugins  = isset( $snap['plugins'] ) && is_array( $snap['plugins'] ) ? $snap['plugins'] : array();
		$total    = count( $plugins );
		$active_n = isset( $snap['active_plugins'] ) ? count( $snap['active_plugins'] ) : 0;
		$inactive = max( 0, $total - $active_n );
		$update_n = isset( $snap['plugin_updates'] ) ? (int) $snap['plugin_updates'] : 0;

		$inactive_names = array();
		$license_ev     = array();
		foreach ( $plugins as $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}
			$name   = isset( $plugin['name'] ) ? (string) $plugin['name'] : ( isset( $plugin['plugin'] ) ? (string) $plugin['plugin'] : '' );
			$file   = isset( $plugin['plugin'] ) ? (string) $plugin['plugin'] : '';
			$status = isset( $plugin['status'] ) ? (string) $plugin['status'] : '';
			if ( 'active' !== $status && 'network-active' !== $status ) {
				$inactive_names[] = $name ? $name : $file;
			}
			if ( preg_match( '/acf|gravity|wpml|elementor pro|divi|yoast premium|rank math pro/i', $name . ' ' . $file ) ) {
				$license_ev[] = $name;
			}
		}
		sort( $inactive_names, SORT_NATURAL | SORT_FLAG_CASE );

		$results   = array();
		$results[] = YWA_Helpers::result(
			'installed_plugins',
			$labels['installed_plugins'],
			$total <= 30 ? 'pass' : ( $total <= 45 ? 'warn' : 'fail' ),
			sprintf( '%d plugins installed on selected site (%d active).', $total, $active_n ),
			array(),
			1.2
		);
		$results[] = YWA_Helpers::result(
			'plugin_updates',
			$labels['plugin_updates'],
			0 === $update_n ? 'pass' : ( $update_n <= 3 ? 'warn' : 'fail' ),
			sprintf( '%d plugin(s) on selected site have available updates.', $update_n ),
			array(),
			1.4
		);
		$results[] = YWA_Helpers::result(
			'unused_plugins',
			$labels['unused_plugins'],
			0 === $inactive ? 'pass' : ( $inactive <= 3 ? 'warn' : 'fail' ),
			sprintf( '%d inactive plugin(s) on selected site.', $inactive ),
			array_slice( $inactive_names, 0, 20 ),
			1.3
		);
		$results[] = YWA_Helpers::result(
			'licensing',
			$labels['licensing'],
			'warn',
			'Premium plugins on the selected site should be verified for valid licenses manually.',
			array_slice( $license_ev, 0, 10 ),
			0.9
		);
		$results[] = YWA_Helpers::result(
			'plugin_docs',
			$labels['plugin_docs'],
			'skip',
			'Plugin/project documentation files cannot be read on remote sites.',
			array(),
			1.0
		);

		$html = YWA_Helpers::homepage_html();
		$services = array();
		$map = array(
			'Google Analytics' => 'google-analytics|gtag/js|googletagmanager',
			'Google Tag Manager' => 'googletagmanager.com/gtm',
			'Hotjar' => 'static.hotjar.com',
			'HubSpot' => 'js.hs-scripts.com|hs-analytics',
			'Intercom' => 'widget.intercom.io',
			'Cloudflare' => 'cdnjs.cloudflare|cloudflareinsights',
			'Stripe' => 'js.stripe.com',
		);
		foreach ( $map as $name => $pattern ) {
			if ( preg_match( '/' . $pattern . '/i', $html ) ) {
				$services[] = $name;
			}
		}
		$results[] = YWA_Helpers::result(
			'third_party',
			$labels['third_party'],
			count( $services ) <= 6 ? 'pass' : 'warn',
			sprintf( '%d third-party front-end services detected on selected site homepage.', count( $services ) ),
			$services,
			1.0
		);

		$has_activity = YWA_Helpers::has_active_plugin( array( 'wp-security-audit-log', 'activity-log', 'wp-activity-log', 'simple-history', 'stream' ) );
		$has_aios     = YWA_Helpers::has_active_plugin( array( 'all-in-one-wp-security', 'better-wp-security', 'solid-security' ) );
		$req_status   = ( $has_activity && $has_aios ) ? 'pass' : ( ( $has_activity || $has_aios ) ? 'warn' : 'fail' );
		$results[]    = YWA_Helpers::result(
			'required_plugins',
			$labels['required_plugins'],
			$req_status,
			sprintf( 'WP Activity Log (or equivalent): %s · AIOS (or equivalent): %s', $has_activity ? 'yes' : 'no', $has_aios ? 'yes' : 'no' ),
			array(),
			1.4
		);

		return $results;
	}
}
