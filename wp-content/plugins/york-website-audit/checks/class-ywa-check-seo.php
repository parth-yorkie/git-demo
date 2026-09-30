<?php
/**
 * Category 5: SEO, Analytics & Redirect Management checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_SEO {

	/**
	 * @return array[]
	 */
	public static function run() {
		$labels  = YWA_Categories::get( 'seo_analytics' )['checks'];
		$html    = YWA_Helpers::homepage_html();
		$results = array();

		// Metadata.
		$has_title = (bool) preg_match( '/<title>[^<]{3,}<\/title>/i', $html );
		$has_desc  = (bool) preg_match( '/name=["\']description["\']/i', $html );
		$has_og    = (bool) preg_match( '/property=["\']og:/i', $html );
		$meta_n    = (int) $has_title + (int) $has_desc + (int) $has_og;
		$seo_plugin = YWA_Helpers::has_active_plugin( array( 'wordpress-seo', 'seo-by-rank-math', 'all-in-one-seo', 'wp-seopress', 'the-seo-framework' ) );
		$results[] = YWA_Helpers::result(
			'metadata',
			$labels['metadata'],
			( $meta_n >= 2 || $seo_plugin ) ? 'pass' : ( $meta_n >= 1 ? 'warn' : 'fail' ),
			sprintf( 'Homepage title/description/OG signals: %d/3. SEO plugin: %s.', $meta_n, $seo_plugin ? 'yes' : 'no' ),
			array(),
			1.3
		);

		// Search Console — look for verification meta / file.
		$gsc = (bool) preg_match( '/google-site-verification/i', $html );
		if ( ! YWA_Helpers::is_remote_audit() ) {
			$gsc_files = glob( ABSPATH . 'google*.html' );
			if ( ! empty( $gsc_files ) ) {
				$gsc = true;
			}
		}
		$results[] = YWA_Helpers::result(
			'search_console',
			$labels['search_console'],
			$gsc ? 'pass' : 'warn',
			$gsc ? 'Google Search Console verification signal found.' : 'No Search Console verification meta/file detected. Confirm in GSC manually.',
			array(),
			1.0
		);

		// Sitemap.
		$sitemap_urls = array(
			YWA_Helpers::target_home_url( '/sitemap.xml' ),
			YWA_Helpers::target_home_url( '/sitemap_index.xml' ),
			YWA_Helpers::target_home_url( '/wp-sitemap.xml' ),
		);
		$sitemap_ok = false;
		$sitemap_hit = '';
		foreach ( $sitemap_urls as $url ) {
			$resp = wp_remote_head( $url, array( 'timeout' => 10, 'sslverify' => false, 'redirection' => 3 ) );
			if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) < 400 ) {
				$sitemap_ok  = true;
				$sitemap_hit = $url;
				break;
			}
		}
		$results[] = YWA_Helpers::result(
			'sitemap',
			$labels['sitemap'],
			$sitemap_ok ? 'pass' : 'fail',
			$sitemap_ok ? 'Sitemap reachable: ' . $sitemap_hit : 'No sitemap found at common URLs.',
			$sitemap_urls,
			1.3
		);

		// Analytics.
		$analytics = array();
		if ( preg_match( '/gtag\/js|google-analytics.com\/analytics|G-[A-Z0-9]+|UA-\d+/i', $html ) ) {
			$analytics[] = 'Google Analytics';
		}
		if ( preg_match( '/googletagmanager.com\/gtm/i', $html ) ) {
			$analytics[] = 'Google Tag Manager';
		}
		if ( preg_match( '/plausible\.io|fathom\.|matomo\.|segment\./i', $html ) ) {
			$analytics[] = 'Alternative analytics';
		}
		$results[] = YWA_Helpers::result(
			'analytics',
			$labels['analytics'],
			! empty( $analytics ) ? 'pass' : 'fail',
			! empty( $analytics ) ? 'Analytics tracking detected.' : 'No analytics tracking snippet found on homepage.',
			$analytics,
			1.4
		);

		// Redirects.
		$redir_plugin = YWA_Helpers::has_active_plugin( array( 'redirection', 'safe-redirect-manager', 'simple-301-redirects', 'wordpress-seo', 'seo-by-rank-math', 'all-in-one-seo' ) );
		$results[] = YWA_Helpers::result(
			'redirects',
			$labels['redirects'],
			$redir_plugin ? 'pass' : 'warn',
			$redir_plugin ? 'Redirect management capability detected via plugin.' : 'No dedicated redirect manager detected.',
			array(),
			1.1
		);

		// Event tracking.
		$events = (bool) preg_match( '/gtag\(\s*[\'"]event|dataLayer\.push|fbq\(\s*[\'"]track/i', $html );
		$results[] = YWA_Helpers::result(
			'event_tracking',
			$labels['event_tracking'],
			$events ? 'pass' : 'warn',
			$events ? 'Front-end event tracking calls found.' : 'No obvious custom event tracking found on homepage (may live in GTM).',
			array(),
			1.0
		);

		// robots.txt
		$robots_url = YWA_Helpers::target_home_url( '/robots.txt' );
		$robots_resp = wp_remote_get( $robots_url, array( 'timeout' => 10, 'sslverify' => false ) );
		$robots_body = is_wp_error( $robots_resp ) ? '' : (string) wp_remote_retrieve_body( $robots_resp );
		$robots_code = is_wp_error( $robots_resp ) ? 0 : (int) wp_remote_retrieve_response_code( $robots_resp );
		$disallow_all = (bool) preg_match( '/Disallow:\s*\/\s*$/mi', $robots_body );
		$has_sitemap_line = (bool) preg_match( '/Sitemap:/i', $robots_body );
		$robots_status = 'fail';
		if ( $robots_code >= 200 && $robots_code < 400 && ! $disallow_all ) {
			$robots_status = $has_sitemap_line ? 'pass' : 'warn';
		} elseif ( $disallow_all ) {
			$robots_status = 'fail';
		}
		$results[] = YWA_Helpers::result(
			'robots_txt',
			$labels['robots_txt'],
			$robots_status,
			sprintf( 'robots.txt HTTP %d. Disallow-all: %s. Sitemap directive: %s.', $robots_code, $disallow_all ? 'yes' : 'no', $has_sitemap_line ? 'yes' : 'no' ),
			array( $robots_url ),
			1.2
		);

		// Broken links — sample homepage anchors.
		$broken = self::sample_broken_links( $html );
		$results[] = YWA_Helpers::result(
			'broken_links',
			$labels['broken_links'],
			0 === count( $broken ) ? 'pass' : ( count( $broken ) <= 2 ? 'warn' : 'fail' ),
			sprintf( 'Checked sample of homepage internal links; %d broken/error responses.', count( $broken ) ),
			$broken,
			1.1
		);

		// AEO/GEO — schema / structured data / AI-oriented signals.
		$has_jsonld = (bool) preg_match( '/application\/ld\+json/i', $html );
		$has_schema = $has_jsonld || (bool) preg_match( '/itemtype=["\']https?:\/\/schema\.org/i', $html );
		$results[] = YWA_Helpers::result(
			'aeo_geo',
			$labels['aeo_geo'],
			$has_schema ? 'pass' : 'warn',
			$has_schema ? 'Structured data (JSON-LD/schema) found — helpful for AEO/GEO.' : 'No structured data detected for answer-engine optimization.',
			array(),
			1.0
		);

		// Canonical.
		$canonical = (bool) preg_match( '/rel=["\']canonical["\']/i', $html );
		$results[] = YWA_Helpers::result(
			'canonical',
			$labels['canonical'],
			$canonical ? 'pass' : 'warn',
			$canonical ? 'Canonical tag present on homepage.' : 'No canonical tag found on homepage.',
			array(),
			1.2
		);

		return $results;
	}

	/**
	 * @param string $html Homepage HTML.
	 * @return string[]
	 */
	private static function sample_broken_links( $html ) {
		preg_match_all( '/href=["\']([^"\']+)["\']/i', $html, $m );
		$hrefs = isset( $m[1] ) ? $m[1] : array();
		$home  = YWA_Helpers::target_home_url( '/' );
		$host  = wp_parse_url( $home, PHP_URL_HOST );
		$candidates = array();
		foreach ( $hrefs as $href ) {
			if ( 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) || 0 === strpos( $href, 'javascript:' ) ) {
				continue;
			}
			if ( 0 === strpos( $href, '/' ) ) {
				$href = YWA_Helpers::target_home_url( $href );
			}
			$h = wp_parse_url( $href, PHP_URL_HOST );
			if ( $h && $host && strtolower( $h ) !== strtolower( $host ) ) {
				continue;
			}
			if ( $h || 0 === strpos( $href, untrailingslashit( $home ) ) ) {
				$candidates[] = $href;
			}
		}
		$candidates = array_values( array_unique( $candidates ) );
		$candidates = array_slice( $candidates, 0, 12 );
		$broken = array();
		foreach ( $candidates as $url ) {
			$resp = wp_remote_head(
				$url,
				array(
					'timeout'     => 8,
					'sslverify'   => false,
					'redirection' => 3,
				)
			);
			if ( is_wp_error( $resp ) ) {
				$broken[] = $url . ' — ' . $resp->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( $code >= 400 || 0 === $code ) {
				$broken[] = $url . ' — HTTP ' . $code;
			}
		}
		return $broken;
	}
}
