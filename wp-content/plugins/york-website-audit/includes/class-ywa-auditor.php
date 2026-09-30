<?php
/**
 * Orchestrates category checks and builds a scored report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once YWA_PLUGIN_DIR . 'includes/class-ywa-helpers.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-code-quality.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-performance.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-security.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-plugins.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-seo.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-docs.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-css.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-cms.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-backup.php';
require_once YWA_PLUGIN_DIR . 'checks/class-ywa-check-production.php';

class YWA_Auditor {

	/**
	 * Option key for the latest report (local admin vs remote frontend).
	 *
	 * @param bool|null $remote Force remote key when true/false; auto-detect when null.
	 * @return string
	 */
	public static function report_option_key( $remote = null ) {
		if ( null === $remote ) {
			$remote = class_exists( 'YWA_Front_Target' ) && YWA_Front_Target::active();
		}
		return $remote ? 'ywa_last_report_remote' : 'ywa_last_report';
	}

	/**
	 * @param bool|null $remote Remote report flag.
	 * @return array
	 */
	public static function get_report( $remote = null ) {
		$report = get_option( self::report_option_key( $remote ), array() );
		return is_array( $report ) ? $report : array();
	}

	/**
	 * Map category id => runner class.
	 *
	 * @return array<string, string>
	 */
	private static function runners() {
		return array(
			'code_quality'          => 'YWA_Check_Code_Quality',
			'performance'           => 'YWA_Check_Performance',
			'security'              => 'YWA_Check_Security',
			'plugin_governance'     => 'YWA_Check_Plugins',
			'seo_analytics'         => 'YWA_Check_SEO',
			'technical_docs'        => 'YWA_Check_Docs',
			'css_typography'        => 'YWA_Check_CSS',
			'cms_admin'             => 'YWA_Check_CMS',
			'backup_recoverability' => 'YWA_Check_Backup',
			'production_readiness'  => 'YWA_Check_Production',
		);
	}

	/**
	 * Ordered category ids for stepped audits.
	 *
	 * @return string[]
	 */
	public static function category_ids() {
		return array_keys( self::runners() );
	}

	/**
	 * Fresh report shell.
	 *
	 * @return array
	 */
	public static function blank_report() {
		$site = array(
			'name' => get_bloginfo( 'name' ),
			'url'  => home_url( '/' ),
		);
		// Frontend remote target overrides report site identity (admin stays local).
		if ( class_exists( 'YWA_Front_Target' ) ) {
			$remote = YWA_Front_Target::site();
			if ( is_array( $remote ) ) {
				$site = $remote;
			}
		}

		return array(
			'site'          => $site,
			'generated'     => current_time( 'mysql' ),
			'generated_gmt' => current_time( 'mysql', true ),
			'version'       => YWA_VERSION,
			'categories'    => array(),
			'overall'       => array(
				'score'   => 0,
				'max'     => 50,
				'percent' => 0,
				'average' => 0,
			),
		);
	}

	/**
	 * Run full audit.
	 *
	 * @param array|null $category_ids Optional subset of category ids.
	 * @return array
	 */
	public static function run( $category_ids = null ) {
		@set_time_limit( 420 );

		$report = self::blank_report();
		$ids    = is_array( $category_ids ) ? $category_ids : self::category_ids();

		foreach ( $ids as $id ) {
			$report = self::run_category( $id, $report, false );
		}

		update_option( self::report_option_key(), $report, false );
		return $report;
	}

	/**
	 * Run a single category and merge into a report.
	 *
	 * @param string     $category_id Category id.
	 * @param array|null $report Existing report or null to start fresh.
	 * @param bool       $persist Whether to save option.
	 * @return array
	 */
	public static function run_category( $category_id, $report = null, $persist = true ) {
		@set_time_limit( 420 );

		$categories = YWA_Categories::all();
		$runners    = self::runners();

		if ( ! isset( $runners[ $category_id ] ) || ! isset( $categories[ $category_id ] ) ) {
			return is_array( $report ) ? $report : self::blank_report();
		}

		if ( ! is_array( $report ) ) {
			$report = self::blank_report();
		}

		$meta    = $categories[ $category_id ];
		$class   = $runners[ $category_id ];
		$checks  = call_user_func( array( $class, 'run' ) );
		$scoring = YWA_Scorer::score_category( $checks );
		$answer  = isset( $meta['answers'][ $scoring['score'] ] ) ? $meta['answers'][ $scoring['score'] ] : '';

		$report['categories'][ $category_id ] = array(
			'id'       => $category_id,
			'number'   => $meta['number'],
			'title'    => $meta['title'],
			'question' => $meta['question'],
			'score'    => $scoring['score'],
			'label'    => $scoring['label'],
			'answer'   => $answer,
			'ratio'    => $scoring['ratio'],
			'stats'    => array(
				'passed'  => $scoring['passed'],
				'warned'  => $scoring['warned'],
				'failed'  => $scoring['failed'],
				'skipped' => $scoring['skipped'],
			),
			'checks'   => $checks,
		);

		$report['generated']     = current_time( 'mysql' );
		$report['generated_gmt'] = current_time( 'mysql', true );
		$report['overall']       = self::overall_from_report( $report );

		if ( $persist ) {
			update_option( self::report_option_key(), $report, false );
		}

		return $report;
	}

	/**
	 * @param array $report Report.
	 * @return array
	 */
	private static function overall_from_report( array $report ) {
		$score_map = array();
		if ( ! empty( $report['categories'] ) && is_array( $report['categories'] ) ) {
			foreach ( $report['categories'] as $id => $cat ) {
				if ( isset( $cat['score'] ) ) {
					$score_map[ $id ] = (int) $cat['score'];
				}
			}
		}
		return YWA_Scorer::overall( $score_map );
	}
}
