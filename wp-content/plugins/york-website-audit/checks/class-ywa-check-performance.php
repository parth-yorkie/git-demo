<?php
/**
 * Category 2: Performance Optimization checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_Performance {

	/**
	 * @return array[]
	 */
	public static function run() {
		$labels = YWA_Categories::get( 'performance' )['checks'];
		$html   = YWA_Helpers::homepage_html();
		$results = array();

		$lh = YWA_Lighthouse::run();

		if ( ! empty( $lh['ok'] ) ) {
			$badge_groups = array();
			$cwv_groups   = array();
			$parts        = array();

			foreach ( array( 'mobile' => 'Mobile', 'desktop' => 'Desktop' ) as $key => $title ) {
				if ( empty( $lh[ $key ]['ok'] ) ) {
					$parts[] = $title . ': unavailable';
					continue;
				}
				$strategy_scores  = $lh[ $key ]['scores'];
				$strategy_metrics = $lh[ $key ]['metrics'];
				$parts[]          = sprintf( '%s Performance %d', $title, (int) $strategy_scores['performance'] );

				$badge_groups[] = array(
					'title'  => $title,
					'badges' => array_merge(
						YWA_Lighthouse::score_badges( $strategy_scores ),
						YWA_Lighthouse::metric_badges( $strategy_metrics )
					),
				);

				$cwv_groups[] = array(
					'title'  => $title,
					'badges' => YWA_Lighthouse::metric_badges( $strategy_metrics, array( 'LCP', 'CLS', 'TBT', 'FCP', 'SI' ) ),
				);
			}

			$opportunity_groups = ! empty( $lh['opportunities'] ) && is_array( $lh['opportunities'] ) ? $lh['opportunities'] : array();

			$results[] = YWA_Helpers::result(
				'lighthouse',
				$labels['lighthouse'],
				YWA_Lighthouse::overall_status( $lh ),
				'Lighthouse category scores and lab metrics — ' . implode( ' · ', $parts ) . '.',
				array( 'URL: ' . $lh['url'] ),
				1.5,
				array(
					'badge_groups' => $badge_groups,
					'links'        => ! empty( $lh['report_url'] )
						? array(
							array(
								'label' => 'Open full Lighthouse report',
								'url'   => $lh['report_url'],
							),
						)
						: array(),
				)
			);

			$cwv_extra = array(
				'badge_groups' => $cwv_groups,
				'tips_label'   => 'How to improve?',
			);
			if ( ! empty( $opportunity_groups ) ) {
				$cwv_extra['opportunity_groups'] = $opportunity_groups;
			} else {
				$cwv_extra['tips'] = array( 'No site-specific Lighthouse asset opportunities were returned for this URL.' );
			}

			$results[] = YWA_Helpers::result(
				'core_web_vitals',
				$labels['core_web_vitals'],
				YWA_Lighthouse::overall_status( $lh ),
				empty( $cwv_groups )
					? 'Lighthouse Core Web Vital metrics unavailable in response.'
					: 'Lighthouse lab Core Web Vital metrics (mobile + desktop).',
				array(),
				1.3,
				$cwv_extra
			);
		} else {
			$error = isset( $lh['error'] ) ? $lh['error'] : 'Unknown Lighthouse error.';
			$hint  = ' Google must be able to reach the URL. For Local sites, set YWA_LIGHTHOUSE_URL in wp-config.php to a public URL.';
			$results[] = YWA_Helpers::result(
				'lighthouse',
				$labels['lighthouse'],
				'fail',
				'Lighthouse run failed: ' . $error . $hint,
				array( 'URL: ' . ( isset( $lh['url'] ) ? $lh['url'] : '' ) ),
				1.5
			);

			// Fallback CWV heuristics when Lighthouse is unavailable.
			$cwv_signals  = 0;
			$cwv_evidence = array();
			if ( false !== stripos( $html, 'loading="lazy"' ) || false !== stripos( $html, "loading='lazy'" ) ) {
				++$cwv_signals;
				$cwv_evidence[] = 'Lazy-loading attributes found';
			}
			if ( preg_match( '/rel=["\']preload["\']/', $html ) ) {
				++$cwv_signals;
				$cwv_evidence[] = 'Preload hints found';
			}
			if ( preg_match( '/fetchpriority=["\']high["\']/', $html ) ) {
				++$cwv_signals;
				$cwv_evidence[] = 'fetchpriority hints found';
			}
			$results[] = YWA_Helpers::result(
				'core_web_vitals',
				$labels['core_web_vitals'],
				$cwv_signals >= 2 ? 'pass' : ( $cwv_signals >= 1 ? 'warn' : 'fail' ),
				sprintf( 'Lighthouse unavailable — used %d/3 HTML CWV signals as fallback.', $cwv_signals ),
				$cwv_evidence,
				1.2
			);
		}

		// Caching.
		$cache_plugins = array( 'wp-super-cache', 'w3-total-cache', 'litespeed-cache', 'wp-rocket', 'cache-enabler', 'comet-cache', 'sg-cachepress', 'breeze', 'nitropack', 'autoptimize' );
		$has_cache = YWA_Helpers::has_active_plugin( $cache_plugins );
		$has_object = defined( 'WP_CACHE' ) && WP_CACHE;
		$results[] = YWA_Helpers::result(
			'caching',
			$labels['caching'],
			( $has_cache || $has_object ) ? 'pass' : 'fail',
			$has_cache || $has_object
				? 'Caching appears enabled (plugin and/or WP_CACHE).'
				: 'No common caching plugin active and WP_CACHE not enabled.',
			array(
				'WP_CACHE: ' . ( $has_object ? 'true' : 'false' ),
				'Cache plugin active: ' . ( $has_cache ? 'yes' : 'no' ),
			),
			1.4
		);

		// Image formats WebP.
		$webp_count = self::count_media_by_mime( array( 'image/webp' ) );
		$jpeg_png   = self::count_media_by_mime( array( 'image/jpeg', 'image/png' ) );
		$html_webp  = ( false !== stripos( $html, '.webp' ) || false !== stripos( $html, 'image/webp' ) );
		$results[]  = YWA_Helpers::result(
			'image_formats',
			$labels['image_formats'],
			( $webp_count > 0 || $html_webp ) ? 'pass' : ( $jpeg_png > 0 ? 'warn' : 'fail' ),
			sprintf( 'Media library WebP: %d · JPEG/PNG: %d · WebP in homepage HTML: %s', $webp_count, $jpeg_png, $html_webp ? 'yes' : 'no' ),
			array(),
			1.2
		);

		// Image optimization plugins.
		$img_opt = YWA_Helpers::has_active_plugin( array( 'imagify', 'shortpixel', 'smush', 'ewww', 'optimole', 'tiny-compress', 'compressor' ) );
		$results[] = YWA_Helpers::result(
			'image_optimization',
			$labels['image_optimization'],
			$img_opt ? 'pass' : 'warn',
			$img_opt ? 'Image optimization plugin detected.' : 'No common image optimization plugin detected.',
			array(),
			1.1
		);

		// CSS/JS assets on homepage.
		preg_match_all( '/<link[^>]+stylesheet[^>]*>/i', $html, $css_m );
		preg_match_all( '/<script[^>]+src=/i', $html, $js_m );
		$css_n = isset( $css_m[0] ) ? count( $css_m[0] ) : 0;
		$js_n  = isset( $js_m[0] ) ? count( $js_m[0] ) : 0;
		$results[] = YWA_Helpers::result(
			'css_js_assets',
			$labels['css_js_assets'],
			( $css_n + $js_n ) <= 25 ? 'pass' : ( ( $css_n + $js_n ) <= 40 ? 'warn' : 'fail' ),
			sprintf( 'Homepage loads ~%d stylesheets and ~%d script tags.', $css_n, $js_n ),
			array(),
			1.1
		);

		// Minification / compression.
		$minified = (bool) preg_match( '/\.(min\.(css|js))(\?|"|\')/i', $html );
		$has_minify_plugin = YWA_Helpers::has_active_plugin( array( 'autoptimize', 'litespeed-cache', 'wp-rocket', 'fast-velocity', 'minify' ) );
		$results[] = YWA_Helpers::result(
			'minification',
			$labels['minification'],
			( $minified || $has_minify_plugin ) ? 'pass' : 'warn',
			( $minified || $has_minify_plugin )
				? 'Minified assets and/or a minification plugin detected.'
				: 'Little evidence of CSS/JS minification on the homepage.',
			array(),
			1.1
		);

		// Unused CSS/JS — heuristic.
		$results[] = YWA_Helpers::result(
			'unused_assets',
			$labels['unused_assets'],
			( $css_n + $js_n ) > 35 ? 'fail' : ( ( $css_n + $js_n ) > 25 ? 'warn' : 'pass' ),
			'High asset counts often indicate unused CSS/JS. Confirm with Lighthouse unused CSS/JS audits.',
			array(),
			1.0
		);

		// Accessibility — prefer Lighthouse score, else HTML heuristics.
		if ( ! empty( $lh['ok'] ) ) {
			$a11y_bits   = array();
			$a11y_scores = array();
			if ( ! empty( $lh['mobile']['ok'] ) ) {
				$mobile_a11y   = (int) $lh['mobile']['scores']['accessibility'];
				$a11y_bits[]   = 'Mobile ' . $mobile_a11y;
				$a11y_scores[] = $mobile_a11y;
			}
			if ( ! empty( $lh['desktop']['ok'] ) ) {
				$desktop_a11y  = (int) $lh['desktop']['scores']['accessibility'];
				$a11y_bits[]   = 'Desktop ' . $desktop_a11y;
				$a11y_scores[] = $desktop_a11y;
			}
			$a11y_score = ! empty( $a11y_scores ) ? min( $a11y_scores ) : 0;
			$results[]  = YWA_Helpers::result(
				'accessibility',
				$labels['accessibility'],
				YWA_Lighthouse::status_from_score( $a11y_score ),
				'Lighthouse accessibility — ' . ( $a11y_bits ? implode( ' · ', $a11y_bits ) : ( $a11y_score . '/100' ) ) . '.',
				array(),
				1.2
			);
		} else {
			$a11y_pass = 0;
			$a11y_ev   = array();
			if ( preg_match( '/<html[^>]+lang=/i', $html ) ) {
				++$a11y_pass;
				$a11y_ev[] = 'html lang attribute present';
			}
			if ( preg_match( '/skip[- ]?(to|link)|screen-reader-text/i', $html ) ) {
				++$a11y_pass;
				$a11y_ev[] = 'Skip link / screen-reader text found';
			}
			$missing_alt = preg_match_all( '/<img(?![^>]*alt=)[^>]*>/i', $html, $m );
			if ( 0 === $missing_alt ) {
				++$a11y_pass;
				$a11y_ev[] = 'No images without alt on homepage sample';
			} else {
				$a11y_ev[] = $missing_alt . ' homepage images missing alt';
			}
			$results[] = YWA_Helpers::result(
				'accessibility',
				$labels['accessibility'],
				$a11y_pass >= 3 ? 'pass' : ( $a11y_pass >= 2 ? 'warn' : 'fail' ),
				sprintf( '%d/3 basic accessibility signals passed on homepage.', $a11y_pass ),
				$a11y_ev,
				1.2
			);
		}

		// Agentic browsing — manual confirmation.
		$results[] = YWA_Helpers::result(
			'agentic_browsing',
			$labels['agentic_browsing'],
			'skip',
			'Manually browse key templates (home, inner, forms, mobile) and note UX/perf issues.',
			array(),
			0.6
		);

		// Image alt text in media library.
		$alt_stats = self::media_alt_stats();
		$ratio = $alt_stats['total'] > 0 ? $alt_stats['with_alt'] / $alt_stats['total'] : 1;
		$results[] = YWA_Helpers::result(
			'image_alt_text',
			$labels['image_alt_text'],
			$ratio >= 0.85 ? 'pass' : ( $ratio >= 0.5 ? 'warn' : 'fail' ),
			sprintf( '%d of %d images in media library have alt text (sample of recent images).', $alt_stats['with_alt'], $alt_stats['total'] ),
			array(),
			1.2
		);

		return $results;
	}

	/**
	 * @param string[] $mimes Mime types.
	 * @return int
	 */
	private static function count_media_by_mime( array $mimes ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_mime_type' => $mimes,
				'no_found_rows'  => false,
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * @return array{total:int,with_alt:int}
	 */
	private static function media_alt_stats() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 40,
				'fields'         => 'ids',
				'post_mime_type' => 'image',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$total = count( $ids );
		$with  = 0;
		foreach ( $ids as $id ) {
			$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( is_string( $alt ) && '' !== trim( $alt ) ) {
				++$with;
			}
		}
		return array(
			'total'    => $total,
			'with_alt' => $with,
		);
	}
}
