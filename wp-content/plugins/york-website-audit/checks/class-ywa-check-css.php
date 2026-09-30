<?php
/**
 * Category 7: CSS Architecture & Typography System checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_CSS {

	/**
	 * @return array[]
	 */
	public static function run() {
		if ( YWA_Helpers::is_remote_audit() ) {
			return YWA_Helpers::skip_category_for_remote(
				'css_typography',
				__( 'CSS architecture checks require the target theme filesystem. Skipped for remote site audits.', 'york-website-audit' )
			);
		}

		$labels  = YWA_Categories::get( 'css_typography' )['checks'];
		$theme   = YWA_Helpers::theme_dir();
		$parent  = YWA_Helpers::parent_theme_dir();
		$results = array();

		$scss = array_merge(
			YWA_Helpers::find_files( $theme, array( 'scss', 'sass' ), 40 ),
			YWA_Helpers::find_files( $parent, array( 'scss', 'sass' ), 40 )
		);
		$css = array_merge(
			YWA_Helpers::find_files( $theme, array( 'css' ), 40 ),
			YWA_Helpers::find_files( $parent, array( 'css' ), 40 )
		);

		$results[] = YWA_Helpers::result(
			'scss_structure',
			$labels['scss_structure'],
			count( $scss ) >= 3 ? 'pass' : ( count( $scss ) >= 1 ? 'warn' : 'fail' ),
			sprintf( 'Found %d SCSS/Sass file(s) in theme.', count( $scss ) ),
			array_map( array( 'YWA_Helpers', 'rel_path' ), array_slice( $scss, 0, 8 ) ),
			1.3
		);

		// Naming conventions (BEM-ish / kebab).
		$sample = YWA_Helpers::sample( array_merge( $scss, $css ), 12 );
		$bem_hits = 0;
		$checked  = 0;
		foreach ( $sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			if ( '' === $content ) {
				continue;
			}
			++$checked;
			if ( preg_match( '/\.[a-z0-9]+(__|--|-)[a-z0-9_-]+/i', $content ) ) {
				++$bem_hits;
			}
		}
		$ratio = $checked > 0 ? $bem_hits / $checked : 0;
		$results[] = YWA_Helpers::result(
			'naming_conventions',
			$labels['naming_conventions'],
			$ratio >= 0.4 ? 'pass' : ( $ratio >= 0.15 ? 'warn' : 'fail' ),
			sprintf( '%d of %d sampled stylesheets show BEM/structured class naming.', $bem_hits, $checked ),
			array(),
			1.1
		);

		// Responsive.
		$mq = 0;
		foreach ( $sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			$mq     += preg_match_all( '/@media\s*[^{]+\{/', $content );
		}
		$html = YWA_Helpers::homepage_html();
		$viewport = (bool) preg_match( '/name=["\']viewport["\']/i', $html );
		$results[] = YWA_Helpers::result(
			'responsive',
			$labels['responsive'],
			( $viewport && $mq >= 3 ) ? 'pass' : ( ( $viewport || $mq >= 1 ) ? 'warn' : 'fail' ),
			sprintf( 'Viewport meta: %s · @media rules in sample: %d.', $viewport ? 'yes' : 'no', $mq ),
			array(),
			1.3
		);

		// REM-based sizing.
		$rem_files = 0;
		$px_heavy  = 0;
		foreach ( $sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			if ( '' === $content ) {
				continue;
			}
			$rems = preg_match_all( '/\d*\.?\d+rem\b/', $content );
			$pxs  = preg_match_all( '/\d+px\b/', $content );
			if ( $rems > 0 ) {
				++$rem_files;
			}
			if ( $pxs > ( $rems * 3 ) && $pxs > 20 ) {
				++$px_heavy;
			}
		}
		$results[] = YWA_Helpers::result(
			'rem_sizing',
			$labels['rem_sizing'],
			$rem_files >= max( 1, (int) floor( count( $sample ) / 3 ) ) ? 'pass' : ( $rem_files > 0 ? 'warn' : 'fail' ),
			sprintf( '%d sampled files use rem units; %d appear px-heavy.', $rem_files, $px_heavy ),
			array(),
			1.2
		);

		// Typography hierarchy.
		$typo_signals = 0;
		$typo_ev = array();
		foreach ( $sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			if ( preg_match( '/font-size\s*:/', $content ) && preg_match( '/h1|h2|\.h1|\.heading/i', $content ) ) {
				++$typo_signals;
			}
			if ( preg_match( '/--font|font-family\s*:/', $content ) ) {
				$typo_ev[] = YWA_Helpers::rel_path( $file ) . ' defines font families/tokens';
			}
		}
		$results[] = YWA_Helpers::result(
			'typography',
			$labels['typography'],
			$typo_signals >= 2 ? 'pass' : ( $typo_signals >= 1 ? 'warn' : 'fail' ),
			sprintf( 'Typography hierarchy signals in %d sampled file(s).', $typo_signals ),
			array_slice( array_unique( $typo_ev ), 0, 6 ),
			1.1
		);

		// Consistency — shared variables / partials.
		$has_vars = false;
		foreach ( array_merge( $scss, $css ) as $file ) {
			$base = strtolower( basename( $file ) );
			if ( preg_match( '/variable|_vars|tokens|theme-json|global/', $base ) ) {
				$has_vars = true;
				break;
			}
			$content = YWA_Helpers::read_file( $file, 8000 );
			if ( preg_match( '/:root\s*\{|--[a-z0-9-]+\s*:/', $content ) ) {
				$has_vars = true;
				break;
			}
		}
		$theme_json = file_exists( $theme . '/theme.json' ) || file_exists( $parent . '/theme.json' );
		$results[] = YWA_Helpers::result(
			'consistency',
			$labels['consistency'],
			( $has_vars || $theme_json ) ? 'pass' : 'warn',
			( $has_vars || $theme_json )
				? 'Shared design tokens / variables / theme.json detected.'
				: 'Limited evidence of shared design tokens across stylesheets.',
			array( 'theme.json: ' . ( $theme_json ? 'yes' : 'no' ) ),
			1.1
		);

		return $results;
	}
}
