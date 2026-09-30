<?php
/**
 * Category 6: Technical Documentation checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_Docs {

	/**
	 * @return array[]
	 */
	public static function run() {
		if ( YWA_Helpers::is_remote_audit() ) {
			return YWA_Helpers::skip_category_for_remote(
				'technical_docs',
				__( 'Technical documentation checks require local project files. Skipped for remote site audits.', 'york-website-audit' )
			);
		}

		$labels  = YWA_Categories::get( 'technical_docs' )['checks'];
		$results = array();
		$theme   = YWA_Helpers::theme_dir();
		$parent  = YWA_Helpers::parent_theme_dir();

		$guide_candidates = array(
			$theme . '/README.md',
			$theme . '/readme.md',
			$theme . '/README.txt',
			$theme . '/docs',
			$theme . '/documentation',
			$parent . '/README.md',
			$parent . '/docs',
			ABSPATH . 'README.md',
			ABSPATH . 'docs',
			WP_CONTENT_DIR . '/README.md',
			$theme . '/MAINTENANCE.md',
			$theme . '/CONTRIBUTING.md',
		);

		$found_guides = array();
		foreach ( $guide_candidates as $path ) {
			if ( file_exists( $path ) ) {
				$found_guides[] = YWA_Helpers::rel_path( $path );
			}
		}

		// Also look for markdown docs under theme.
		$md_files = YWA_Helpers::find_files( $theme, array( 'md' ), 20 );
		foreach ( $md_files as $md ) {
			$rel = YWA_Helpers::rel_path( $md );
			if ( ! in_array( $rel, $found_guides, true ) ) {
				$found_guides[] = $rel;
			}
		}

		$results[] = YWA_Helpers::result(
			'maintenance_guides',
			$labels['maintenance_guides'],
			count( $found_guides ) >= 2 ? 'pass' : ( count( $found_guides ) >= 1 ? 'warn' : 'fail' ),
			sprintf( 'Found %d maintenance/documentation artifact(s).', count( $found_guides ) ),
			array_slice( $found_guides, 0, 12 ),
			1.4
		);

		// API / headless documentation.
		$is_headless_signal = YWA_Helpers::has_active_plugin( array( 'wp-graphql', 'wp-rest-cache', 'jwt-auth', 'headless' ) )
			|| defined( 'GRAPHQL_DEBUG' );
		$api_docs = array();
		$api_paths = array(
			$theme . '/API.md',
			$theme . '/docs/api.md',
			$theme . '/docs/api',
			ABSPATH . 'docs/api.md',
			ABSPATH . 'frontend/README.md',
			ABSPATH . '../frontend/README.md',
		);
		foreach ( $api_paths as $path ) {
			if ( file_exists( $path ) ) {
				$api_docs[] = YWA_Helpers::rel_path( $path );
			}
		}
		// REST index exists by default — documentation quality is what matters.
		$rest = rest_url();
		if ( $is_headless_signal && empty( $api_docs ) ) {
			$results[] = YWA_Helpers::result(
				'api_documentation',
				$labels['api_documentation'],
				'fail',
				'Headless/API signals detected but no API documentation files found.',
				array( 'REST root: ' . $rest ),
				1.3
			);
		} elseif ( ! empty( $api_docs ) ) {
			$results[] = YWA_Helpers::result(
				'api_documentation',
				$labels['api_documentation'],
				'pass',
				'API/front-end documentation files found.',
				$api_docs,
				1.3
			);
		} else {
			$results[] = YWA_Helpers::result(
				'api_documentation',
				$labels['api_documentation'],
				'pass',
				'No headless setup detected; classic WP REST root available. API docs less critical.',
				array( 'REST root: ' . $rest ),
				0.8
			);
		}

		return $results;
	}
}
