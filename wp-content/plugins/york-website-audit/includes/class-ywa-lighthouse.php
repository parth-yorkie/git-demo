<?php
/**
 * Fetch Lighthouse metrics via the PageSpeed Insights API (v5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Lighthouse {

	/** @var array|null Cached combined response for this request. */
	private static $cache = null;

	/**
	 * API key (override with YWA_LIGHTHOUSE_API_KEY in wp-config.php).
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'YWA_LIGHTHOUSE_API_KEY' ) && YWA_LIGHTHOUSE_API_KEY ) {
			return (string) YWA_LIGHTHOUSE_API_KEY;
		}
		return (string) apply_filters( 'ywa_lighthouse_api_key', '' );
	}

	/**
	 * URL to analyze (override with YWA_LIGHTHOUSE_URL for non-public local sites).
	 *
	 * @return string
	 */
	public static function target_url() {
		if ( defined( 'YWA_LIGHTHOUSE_URL' ) && YWA_LIGHTHOUSE_URL ) {
			return (string) YWA_LIGHTHOUSE_URL;
		}
		return (string) apply_filters( 'ywa_lighthouse_url', home_url( '/' ) );
	}

	/**
	 * Run Lighthouse for mobile + desktop and return a normalized payload.
	 *
	 * @return array
	 */
	public static function run() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$url = self::target_url();
		$key = self::api_key();

		if ( '' === $key ) {
			self::$cache = array(
				'ok'    => false,
				'url'   => $url,
				'error' => 'Missing Lighthouse API key. Define YWA_LIGHTHOUSE_API_KEY in wp-config.php.',
			);
			return self::$cache;
		}

		$mobile  = self::fetch_strategy( $url, $key, 'mobile' );
		$desktop = self::fetch_strategy( $url, $key, 'desktop' );

		if ( empty( $mobile['ok'] ) && empty( $desktop['ok'] ) ) {
			$error = ! empty( $mobile['error'] ) ? $mobile['error'] : ( ! empty( $desktop['error'] ) ? $desktop['error'] : 'Lighthouse request failed.' );
			self::$cache = array(
				'ok'    => false,
				'url'   => $url,
				'error' => $error,
			);
			return self::$cache;
		}

		$primary_scores  = ! empty( $mobile['ok'] ) ? $mobile['scores'] : $desktop['scores'];
		$primary_metrics = ! empty( $mobile['ok'] ) ? $mobile['metrics'] : $desktop['metrics'];

		self::$cache = array(
			'ok'             => true,
			'url'            => $url,
			'mobile'         => $mobile,
			'desktop'        => $desktop,
			'scores'         => $primary_scores,
			'metrics'        => $primary_metrics,
			'opportunities'  => self::merge_opportunities( $mobile, $desktop ),
			'report_url'     => 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $url ),
		);

		return self::$cache;
	}

	/**
	 * Fetch one strategy (mobile|desktop).
	 *
	 * @param string $url URL.
	 * @param string $key API key.
	 * @param string $strategy Strategy.
	 * @return array
	 */
	private static function fetch_strategy( $url, $key, $strategy ) {
		$endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query(
			array(
				'url'      => $url,
				'key'      => $key,
				'strategy' => $strategy,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		foreach ( array( 'performance', 'accessibility', 'best-practices', 'seo' ) as $category ) {
			$endpoint .= '&category=' . rawurlencode( $category );
		}

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'   => 120,
				'sslverify' => true,
				'headers'   => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'       => false,
				'strategy' => $strategy,
				'error'    => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $body ) ) {
			$message = 'Lighthouse API request failed (HTTP ' . $code . ').';
			if ( isset( $body['error']['message'] ) ) {
				$message = (string) $body['error']['message'];
			}
			return array(
				'ok'       => false,
				'strategy' => $strategy,
				'error'    => $message,
			);
		}

		$lh         = isset( $body['lighthouseResult'] ) && is_array( $body['lighthouseResult'] ) ? $body['lighthouseResult'] : array();
		$categories = isset( $lh['categories'] ) && is_array( $lh['categories'] ) ? $lh['categories'] : array();
		$audits     = isset( $lh['audits'] ) && is_array( $lh['audits'] ) ? $lh['audits'] : array();

		$scores = array(
			'performance'    => self::score_pct( $categories, 'performance' ),
			'accessibility'  => self::score_pct( $categories, 'accessibility' ),
			'best_practices' => self::score_pct( $categories, 'best-practices' ),
			'seo'            => self::score_pct( $categories, 'seo' ),
		);

		$metrics = array();
		$map     = array(
			'first-contentful-paint'   => 'FCP',
			'largest-contentful-paint' => 'LCP',
			'total-blocking-time'      => 'TBT',
			'cumulative-layout-shift'  => 'CLS',
			'speed-index'              => 'SI',
			'interactive'              => 'TTI',
		);
		foreach ( $map as $audit_id => $label ) {
			if ( empty( $audits[ $audit_id ]['displayValue'] ) ) {
				continue;
			}
			$metrics[ $label ] = (string) $audits[ $audit_id ]['displayValue'];
		}

		return array(
			'ok'            => true,
			'strategy'      => $strategy,
			'scores'        => $scores,
			'metrics'       => $metrics,
			'opportunities' => self::extract_opportunities( $audits, $strategy ),
		);
	}

	/**
	 * Pull structured Lighthouse opportunity / insight audits with asset rows.
	 *
	 * @param array  $audits Audits blob.
	 * @param string $strategy mobile|desktop.
	 * @return array[]
	 */
	private static function extract_opportunities( array $audits, $strategy ) {
		$groups = array();

		foreach ( $audits as $audit_id => $audit ) {
			if ( ! is_array( $audit ) ) {
				continue;
			}

			$details = isset( $audit['details'] ) && is_array( $audit['details'] ) ? $audit['details'] : array();
			$type    = isset( $details['type'] ) ? $details['type'] : '';
			$mode    = isset( $audit['scoreDisplayMode'] ) ? $audit['scoreDisplayMode'] : '';
			$score   = isset( $audit['score'] ) ? $audit['score'] : null;
			$items   = isset( $details['items'] ) && is_array( $details['items'] ) ? $details['items'] : array();

			$is_opportunity = ( 'opportunity' === $type );
			$is_insight     = ( 'metricSavings' === $mode && ! empty( $items ) );
			$is_table_asset = ( 'table' === $type && ! empty( $items ) && self::items_look_like_assets( $items ) );

			if ( ! $is_opportunity && ! $is_insight && ! $is_table_asset ) {
				continue;
			}
			if ( null !== $score && (float) $score >= 0.9 ) {
				continue;
			}
			// Skip noisy diagnostics without actionable savings/assets.
			if ( in_array( $audit_id, array( 'mainthread-work-breakdown', 'total-byte-weight', 'layout-shifts', 'long-tasks', 'bootup-time' ), true ) ) {
				continue;
			}

			$title = isset( $audit['title'] ) ? trim( (string) $audit['title'] ) : '';
			if ( '' === $title ) {
				continue;
			}

			$rows = self::normalize_opportunity_items( $items );
			if ( empty( $rows ) && ! $is_opportunity ) {
				continue;
			}

			$savings_bytes = isset( $details['overallSavingsBytes'] ) ? (float) $details['overallSavingsBytes'] : 0;
			if ( $savings_bytes <= 0 ) {
				foreach ( $rows as $row ) {
					$savings_bytes += isset( $row['savings_bytes'] ) ? (float) $row['savings_bytes'] : 0;
				}
			}

			$savings_ms = isset( $details['overallSavingsMs'] ) ? (float) $details['overallSavingsMs'] : 0;
			$display    = isset( $audit['displayValue'] ) ? (string) $audit['displayValue'] : '';
			if ( '' === $display ) {
				if ( $savings_bytes > 0 ) {
					$display = 'Est savings of ' . self::format_kib( $savings_bytes );
				} elseif ( $savings_ms > 0 ) {
					$display = sprintf( 'Est savings of %s ms', number_format_i18n( $savings_ms ) );
				}
			}

			$desc = isset( $audit['description'] ) ? wp_strip_all_tags( (string) $audit['description'] ) : '';
			$desc = trim( preg_replace( '/\s+/', ' ', $desc ) );

			$metric_tags = array();
			if ( ! empty( $audit['metricSavings'] ) && is_array( $audit['metricSavings'] ) ) {
				foreach ( $audit['metricSavings'] as $metric => $value ) {
					if ( (float) $value > 0 ) {
						$metric_tags[] = strtoupper( (string) $metric );
					}
				}
			}

			$groups[] = array(
				'id'            => (string) $audit_id,
				'title'         => $title,
				'description'   => $desc,
				'display'       => $display,
				'savings_bytes' => $savings_bytes,
				'savings_ms'    => $savings_ms,
				'metrics'       => $metric_tags,
				'strategy'      => $strategy,
				'items'         => array_slice( $rows, 0, 12 ),
			);
		}

		usort(
			$groups,
			function ( $a, $b ) {
				$ab = isset( $a['savings_bytes'] ) ? (float) $a['savings_bytes'] : 0;
				$bb = isset( $b['savings_bytes'] ) ? (float) $b['savings_bytes'] : 0;
				if ( $ab === $bb ) {
					$am = isset( $a['savings_ms'] ) ? (float) $a['savings_ms'] : 0;
					$bm = isset( $b['savings_ms'] ) ? (float) $b['savings_ms'] : 0;
					return $bm <=> $am;
				}
				return $bb <=> $ab;
			}
		);

		return array_slice( $groups, 0, 8 );
	}

	/**
	 * Prefer mobile opportunity groups; fill gaps from desktop.
	 *
	 * @param array $mobile Mobile payload.
	 * @param array $desktop Desktop payload.
	 * @return array[]
	 */
	private static function merge_opportunities( array $mobile, array $desktop ) {
		$merged = array();
		$seen   = array();

		foreach ( array( $mobile, $desktop ) as $payload ) {
			if ( empty( $payload['ok'] ) || empty( $payload['opportunities'] ) || ! is_array( $payload['opportunities'] ) ) {
				continue;
			}
			foreach ( $payload['opportunities'] as $group ) {
				$id = isset( $group['id'] ) ? $group['id'] : '';
				if ( '' === $id || isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$merged[]    = $group;
			}
		}

		return array_slice( $merged, 0, 8 );
	}

	/**
	 * @param array $items Raw detail items.
	 * @return bool
	 */
	private static function items_look_like_assets( array $items ) {
		$sample = reset( $items );
		if ( ! is_array( $sample ) ) {
			return false;
		}
		return isset( $sample['url'] ) || isset( $sample['node'] ) || isset( $sample['wastedBytes'] ) || isset( $sample['wastedMs'] );
	}

	/**
	 * Normalize opportunity/insight rows for UI.
	 *
	 * @param array $items Raw items.
	 * @return array[]
	 */
	private static function normalize_opportunity_items( array $items ) {
		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( is_array( $url ) ) {
				$url = isset( $url['url'] ) ? (string) $url['url'] : '';
			}

			$node_label = '';
			$snippet    = '';
			if ( ! empty( $item['node'] ) && is_array( $item['node'] ) ) {
				$node_label = isset( $item['node']['nodeLabel'] ) ? trim( (string) $item['node']['nodeLabel'] ) : '';
				$snippet    = isset( $item['node']['snippet'] ) ? trim( (string) $item['node']['snippet'] ) : '';
			}

			$label = $node_label;
			if ( '' === $label && $url ) {
				$label = basename( wp_parse_url( $url, PHP_URL_PATH ) );
			}
			if ( '' === $label && ! empty( $item['label'] ) ) {
				$label = (string) $item['label'];
			}
			if ( '' === $label && ! empty( $item['groupLabel'] ) ) {
				$label = (string) $item['groupLabel'];
			}
			if ( '' === $label ) {
				continue;
			}

			$total_bytes  = isset( $item['totalBytes'] ) ? (float) $item['totalBytes'] : 0;
			$wasted_bytes = isset( $item['wastedBytes'] ) ? (float) $item['wastedBytes'] : 0;
			$wasted_ms    = isset( $item['wastedMs'] ) ? (float) $item['wastedMs'] : 0;

			$reasons = array();
			if ( ! empty( $item['subItems']['items'] ) && is_array( $item['subItems']['items'] ) ) {
				foreach ( $item['subItems']['items'] as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					$reason = isset( $sub['reason'] ) ? trim( (string) $sub['reason'] ) : '';
					if ( '' === $reason ) {
						continue;
					}
					$reasons[] = array(
						'text'          => $reason,
						'savings'       => ! empty( $sub['wastedBytes'] ) ? self::format_kib( (float) $sub['wastedBytes'] ) : '',
						'savings_bytes' => isset( $sub['wastedBytes'] ) ? (float) $sub['wastedBytes'] : 0,
					);
				}
			}

			$rows[] = array(
				'label'         => $label,
				'url'           => $url,
				'url_short'     => $url ? self::short_url( $url ) : '',
				'snippet'       => $snippet,
				'size'          => $total_bytes > 0 ? self::format_kib( $total_bytes ) : '',
				'savings'       => $wasted_bytes > 0 ? self::format_kib( $wasted_bytes ) : ( $wasted_ms > 0 ? number_format_i18n( $wasted_ms ) . ' ms' : '' ),
				'savings_bytes' => $wasted_bytes,
				'reasons'       => $reasons,
			);
		}
		return $rows;
	}

	/**
	 * @param float $bytes Bytes.
	 * @return string
	 */
	public static function format_kib( $bytes ) {
		$kib = $bytes / 1024;
		if ( $kib >= 100 ) {
			return number_format_i18n( $kib, 0 ) . ' KiB';
		}
		return number_format_i18n( $kib, 1 ) . ' KiB';
	}

	/**
	 * @param string $url Full URL.
	 * @return string
	 */
	private static function short_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$file = basename( $path );
		if ( strlen( $path ) > 42 ) {
			$path = '…' . substr( $path, -36 );
		}
		return $file ? $file : ( $host . $path );
	}

	/**
	 * Overall status from available strategy performance scores (uses the weaker score).
	 *
	 * @param array $lh Combined lighthouse payload.
	 * @return string
	 */
	public static function overall_status( array $lh ) {
		$scores = array();
		if ( ! empty( $lh['mobile']['ok'] ) ) {
			$scores[] = (int) $lh['mobile']['scores']['performance'];
		}
		if ( ! empty( $lh['desktop']['ok'] ) ) {
			$scores[] = (int) $lh['desktop']['scores']['performance'];
		}
		if ( empty( $scores ) ) {
			return 'fail';
		}
		return self::status_from_score( min( $scores ) );
	}

	/**
	 * Map a 0–100 score to pass/warn/fail.
	 *
	 * @param int $score Score 0–100.
	 * @return string
	 */
	public static function status_from_score( $score ) {
		$score = (int) $score;
		if ( $score >= 90 ) {
			return 'pass';
		}
		if ( $score >= 50 ) {
			return 'warn';
		}
		return 'fail';
	}

	/**
	 * Tone for a Lighthouse category score badge.
	 *
	 * @param int $score 0–100.
	 * @return string pass|warn|fail
	 */
	public static function score_tone( $score ) {
		return self::status_from_score( $score );
	}

	/**
	 * Tone for a lab metric display value.
	 *
	 * @param string $metric Metric key (LCP, CLS, TBT, FCP, SI, TTI).
	 * @param string $display Display value from Lighthouse.
	 * @return string pass|warn|fail
	 */
	public static function metric_tone( $metric, $display ) {
		$metric = strtoupper( (string) $metric );
		$raw    = strtolower( trim( (string) $display ) );
		$num    = null;
		if ( preg_match( '/([0-9]*\.?[0-9]+)/', $raw, $m ) ) {
			$num = (float) $m[1];
		}
		if ( null === $num ) {
			return 'warn';
		}

		$is_ms = ( false !== strpos( $raw, 'ms' ) );

		switch ( $metric ) {
			case 'LCP':
			case 'TTI':
				return $num <= 2.5 ? 'pass' : ( $num <= 4.0 ? 'warn' : 'fail' );
			case 'FCP':
				return $num <= 1.8 ? 'pass' : ( $num <= 3.0 ? 'warn' : 'fail' );
			case 'SI':
				return $num <= 3.4 ? 'pass' : ( $num <= 5.8 ? 'warn' : 'fail' );
			case 'CLS':
				return $num <= 0.1 ? 'pass' : ( $num <= 0.25 ? 'warn' : 'fail' );
			case 'TBT':
				$ms = $is_ms ? $num : ( $num * 1000 );
				return $ms <= 200 ? 'pass' : ( $ms <= 600 ? 'warn' : 'fail' );
			default:
				return 'warn';
		}
	}

	/**
	 * Build UI badge payloads for category scores.
	 *
	 * @param array $scores Scores map.
	 * @return array[]
	 */
	public static function score_badges( array $scores ) {
		$map = array(
			'performance'    => 'Performance',
			'accessibility'  => 'Accessibility',
			'best_practices' => 'Best Practices',
			'seo'            => 'SEO',
		);
		$badges = array();
		foreach ( $map as $key => $label ) {
			if ( ! isset( $scores[ $key ] ) ) {
				continue;
			}
			$score    = (int) $scores[ $key ];
			$badges[] = array(
				'kind'  => 'score',
				'label' => $label,
				'value' => (string) $score,
				'tone'  => self::score_tone( $score ),
			);
		}
		return $badges;
	}

	/**
	 * Build UI badge payloads for lab metrics.
	 *
	 * @param array         $metrics Metrics map.
	 * @param string[]|null $keys Optional key filter/order.
	 * @return array[]
	 */
	public static function metric_badges( array $metrics, $keys = null ) {
		$order  = is_array( $keys ) ? $keys : array( 'FCP', 'LCP', 'TBT', 'CLS', 'SI', 'TTI' );
		$badges = array();
		foreach ( $order as $key ) {
			if ( empty( $metrics[ $key ] ) ) {
				continue;
			}
			$badges[] = array(
				'kind'  => 'metric',
				'label' => $key,
				'value' => (string) $metrics[ $key ],
				'tone'  => self::metric_tone( $key, $metrics[ $key ] ),
			);
		}
		return $badges;
	}

	/**
	 * @param array  $categories Categories blob.
	 * @param string $id Category id.
	 * @return int
	 */
	private static function score_pct( array $categories, $id ) {
		if ( ! isset( $categories[ $id ]['score'] ) || null === $categories[ $id ]['score'] ) {
			return 0;
		}
		return (int) round( ( (float) $categories[ $id ]['score'] ) * 100 );
	}
}
