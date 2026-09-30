<?php
/**
 * Category 1: Code Quality & Documentation checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_Code_Quality {

	/**
	 * @return array[]
	 */
	public static function run() {
		if ( YWA_Helpers::is_remote_audit() ) {
			return YWA_Helpers::skip_category_for_remote(
				'code_quality',
				__( 'Theme PHP/CSS/JS file review requires filesystem access on the target site. Skipped so this panel’s local theme files are not reported for a remote audit.', 'york-website-audit' )
			);
		}

		$cat    = YWA_Categories::get( 'code_quality' );
		$labels = $cat['checks'];
		$theme  = YWA_Helpers::theme_dir();
		$parent = YWA_Helpers::parent_theme_dir();
		$dirs   = array_unique( array( $theme, $parent ) );

		$php_files = array();
		$css_files = array();
		$js_files  = array();
		foreach ( $dirs as $dir ) {
			$php_files = array_merge( $php_files, YWA_Helpers::find_files( $dir, array( 'php' ), 80 ) );
			$css_files = array_merge( $css_files, YWA_Helpers::find_files( $dir, array( 'css', 'scss', 'sass' ), 60 ) );
			$js_files  = array_merge( $js_files, YWA_Helpers::find_files( $dir, array( 'js' ), 60 ) );
		}
		$php_sample = YWA_Helpers::sample( $php_files, 15 );

		$results = array();

		// Comments & documentation.
		$doc_hits = 0;
		$doc_total = 0;
		foreach ( $php_sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			if ( '' === $content ) {
				continue;
			}
			++$doc_total;
			if ( preg_match( '/\/\*\*|@package|@since|@param|@return/', $content ) ) {
				++$doc_hits;
			}
		}
		$doc_ratio = $doc_total > 0 ? $doc_hits / $doc_total : 0;
		$results[] = YWA_Helpers::result(
			'review_comments_docs',
			$labels['review_comments_docs'],
			$doc_ratio >= 0.6 ? 'pass' : ( $doc_ratio >= 0.25 ? 'warn' : 'fail' ),
			sprintf( '%d of %d sampled PHP files have docblocks or documentation tags.', $doc_hits, $doc_total ),
			array(),
			1.2
		);

		// Coding standards heuristics.
		$style_issues = array();
		foreach ( $php_sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			if ( preg_match( '/\t/', $content ) && preg_match( '/^ {2,4}\S/m', $content ) ) {
				$style_issues[] = YWA_Helpers::rel_path( $file ) . ': mixed indentation';
			}
			if ( preg_match( '/\?>\s*$/', $content ) ) {
				$style_issues[] = YWA_Helpers::rel_path( $file ) . ': closing PHP tag';
			}
		}
		$phpcs = file_exists( $theme . '/phpcs.xml' ) || file_exists( $theme . '/phpcs.xml.dist' )
			|| file_exists( $parent . '/phpcs.xml' ) || file_exists( $parent . '/phpcs.xml.dist' )
			|| file_exists( ABSPATH . 'phpcs.xml' ) || file_exists( ABSPATH . 'phpcs.xml.dist' );
		if ( $phpcs ) {
			$results[] = YWA_Helpers::result( 'coding_standards', $labels['coding_standards'], 'pass', 'PHPCS configuration found (linting setup present).', array(), 1.2 );
		} elseif ( count( $style_issues ) > 5 ) {
			$results[] = YWA_Helpers::result( 'coding_standards', $labels['coding_standards'], 'fail', 'No PHPCS config and multiple style inconsistencies detected.', array_slice( $style_issues, 0, 5 ), 1.2 );
		} elseif ( count( $style_issues ) > 0 ) {
			$results[] = YWA_Helpers::result( 'coding_standards', $labels['coding_standards'], 'warn', 'No PHPCS config; minor style inconsistencies found.', array_slice( $style_issues, 0, 5 ), 1.2 );
		} else {
			$results[] = YWA_Helpers::result( 'coding_standards', $labels['coding_standards'], 'warn', 'No PHPCS configuration found; coding standards not automated.', array(), 1.2 );
		}

		// Deprecated functions.
		$deprecated = array(
			'get_page_by_title',
			'mysql_connect',
			'create_function',
			'each(',
			'get_bloginfo_rss',
			'wp_get_http',
			'screen_icon',
			'get_theme_data',
		);
		$dep_hits = array();
		foreach ( $php_sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			foreach ( $deprecated as $fn ) {
				if ( false !== strpos( $content, $fn ) ) {
					$dep_hits[] = YWA_Helpers::rel_path( $file ) . ': ' . $fn;
				}
			}
		}
		$results[] = YWA_Helpers::result(
			'deprecated_functions',
			$labels['deprecated_functions'],
			empty( $dep_hits ) ? 'pass' : ( count( $dep_hits ) > 3 ? 'fail' : 'warn' ),
			empty( $dep_hits ) ? 'No common deprecated functions found in sampled files.' : 'Deprecated or legacy usage detected.',
			array_slice( $dep_hits, 0, 8 ),
			1.3
		);

		// Security: SQL injection / XSS patterns.
		$sec_hits = array();
		foreach ( $php_sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			if ( preg_match( '/\$wpdb->(query|get_results|get_var|get_row)\s*\(\s*[\'"][^\'"]*\$/', $content )
				|| preg_match( '/\$wpdb->(query|get_results|get_var|get_row)\s*\(\s*\$/', $content ) ) {
				if ( ! preg_match( '/\$wpdb->prepare\s*\(/', $content ) ) {
					$sec_hits[] = YWA_Helpers::rel_path( $file ) . ': possible unprepared SQL';
				}
			}
			if ( preg_match( '/echo\s+\$_(GET|POST|REQUEST|COOKIE)/', $content )
				|| preg_match( '/print\s+\$_(GET|POST|REQUEST)/', $content ) ) {
				$sec_hits[] = YWA_Helpers::rel_path( $file ) . ': unescaped superglobal output';
			}
			if ( preg_match( '/eval\s*\(|assert\s*\(|base64_decode\s*\(\s*\$_/', $content ) ) {
				$sec_hits[] = YWA_Helpers::rel_path( $file ) . ': dangerous function usage';
			}
		}
		$results[] = YWA_Helpers::result(
			'security_issues',
			$labels['security_issues'],
			empty( $sec_hits ) ? 'pass' : ( count( $sec_hits ) > 2 ? 'fail' : 'warn' ),
			empty( $sec_hits ) ? 'No obvious SQL injection / XSS patterns in sampled files.' : 'Potential security issues detected.',
			array_slice( $sec_hits, 0, 8 ),
			1.5
		);

		// Escaping & sanitization.
		$esc_ok = 0;
		$esc_files = 0;
		$missing = array();
		foreach ( $php_sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			if ( ! preg_match( '/echo\s+|print\s+|<\?=/', $content ) ) {
				continue;
			}
			++$esc_files;
			$has_escape = preg_match( '/esc_(html|attr|url|js|textarea)|wp_kses|sanitize_/', $content );
			$has_raw    = preg_match( '/echo\s+\$|print\s+\$|<\?=\s*\$/', $content );
			if ( $has_escape ) {
				++$esc_ok;
			} elseif ( $has_raw ) {
				$missing[] = YWA_Helpers::rel_path( $file );
			}
		}
		if ( 0 === $esc_files ) {
			$results[] = YWA_Helpers::result( 'escaping_sanitization', $labels['escaping_sanitization'], 'warn', 'Could not evaluate escaping patterns in sampled files.', array(), 1.3 );
		} else {
			$ratio = $esc_ok / $esc_files;
			$results[] = YWA_Helpers::result(
				'escaping_sanitization',
				$labels['escaping_sanitization'],
				$ratio >= 0.6 ? 'pass' : ( $ratio >= 0.3 ? 'warn' : 'fail' ),
				sprintf( '%d of %d output-containing files use WP escaping/sanitization helpers.', $esc_ok, $esc_files ),
				array_slice( $missing, 0, 6 ),
				1.3
			);
		}

		// Slow queries / N+1 heuristics.
		$n1 = array();
		foreach ( $php_sample as $file ) {
			$content = YWA_Helpers::read_file( $file );
			if ( preg_match( '/foreach\s*\([^)]+\)\s*\{[^}]{0,400}(get_post_meta|get_the_title|wp_get_attachment|get_userdata)/s', $content ) ) {
				$n1[] = YWA_Helpers::rel_path( $file ) . ': possible N+1 in loop';
			}
		}
		$results[] = YWA_Helpers::result(
			'slow_queries',
			$labels['slow_queries'],
			empty( $n1 ) ? 'pass' : ( count( $n1 ) > 3 ? 'fail' : 'warn' ),
			empty( $n1 ) ? 'No obvious N+1 query patterns in sampled files.' : 'Possible N+1 query patterns found.',
			array_slice( $n1, 0, 6 ),
			1.0
		);

		// Full active-theme scan: naming + comments for PHP/CSS/JS.
		$results[] = self::theme_files_audit( $theme, $labels['open_random_files'] );

		// Header comments.
		$headers_ok = 0;
		$header_files = array();
		$style_css = $theme . '/style.css';
		if ( is_readable( $style_css ) ) {
			$header_files[] = $style_css;
		}
		foreach ( array_slice( $php_files, 0, 8 ) as $file ) {
			$header_files[] = $file;
		}
		foreach ( $header_files as $file ) {
			$content = YWA_Helpers::read_file( $file, 4000 );
			if ( preg_match( '/Theme Name:|Plugin Name:|\/\*\*|@package/', $content ) ) {
				++$headers_ok;
			}
		}
		$total_h = count( $header_files );
		$results[] = YWA_Helpers::result(
			'header_comments',
			$labels['header_comments'],
			( $total_h > 0 && $headers_ok / $total_h >= 0.5 ) ? 'pass' : 'warn',
			sprintf( '%d of %d checked files have header/package documentation.', $headers_ok, $total_h ),
			array(),
			1.0
		);

		return $results;
	}

	/**
	 * Scan every PHP/CSS/JS file in the active theme for naming + commenting.
	 *
	 * @param string $theme_dir Active theme directory.
	 * @param string $label Check label.
	 * @return array
	 */
	private static function theme_files_audit( $theme_dir, $label ) {
		$php_files = YWA_Helpers::find_files( $theme_dir, array( 'php' ), 400 );
		$css_files = YWA_Helpers::find_files( $theme_dir, array( 'css', 'scss', 'sass' ), 400 );
		$js_files  = YWA_Helpers::find_files( $theme_dir, array( 'js' ), 400 );

		$issues = array();
		$stats  = array(
			'php_files'             => count( $php_files ),
			'css_files'             => count( $css_files ),
			'js_files'              => count( $js_files ),
			'php_bad_filenames'     => 0,
			'css_bad_filenames'     => 0,
			'js_bad_filenames'      => 0,
			'php_functions'         => 0,
			'php_functions_named'   => 0,
			'php_functions_docs'    => 0,
			'php_hooks'             => 0,
			'php_hooks_docs'        => 0,
			'css_with_comments'     => 0,
			'css_checked'           => 0,
			'js_functions'          => 0,
			'js_functions_docs'     => 0,
			'js_checked'            => 0,
		);

		foreach ( $php_files as $file ) {
			$rel  = YWA_Helpers::rel_path( $file );
			$base = basename( $file );

			if ( ! self::is_valid_asset_filename( $base, 'php' ) ) {
				++$stats['php_bad_filenames'];
				$issues[] = $rel . ': invalid PHP filename (use lowercase kebab/snake-case)';
			}

			$content = YWA_Helpers::read_file( $file, 350000 );
			if ( '' === $content ) {
				continue;
			}

			$php_scan = self::scan_php_functions_and_hooks( $content, $rel );
			$stats['php_functions']       += $php_scan['functions'];
			$stats['php_functions_named'] += $php_scan['functions_named'];
			$stats['php_functions_docs']  += $php_scan['functions_docs'];
			$stats['php_hooks']           += $php_scan['hooks'];
			$stats['php_hooks_docs']      += $php_scan['hooks_docs'];
			foreach ( array_slice( $php_scan['issues'], 0, 8 ) as $issue ) {
				$issues[] = $issue;
			}
		}

		foreach ( $css_files as $file ) {
			$rel  = YWA_Helpers::rel_path( $file );
			$base = basename( $file );

			if ( self::is_minified_filename( $base ) ) {
				continue;
			}

			if ( ! self::is_valid_asset_filename( $base, 'css' ) ) {
				++$stats['css_bad_filenames'];
				$issues[] = $rel . ': invalid CSS/SCSS filename (use lowercase kebab-case)';
			}

			$content = YWA_Helpers::read_file( $file, 250000 );
			if ( '' === $content ) {
				continue;
			}
			++$stats['css_checked'];
			if ( self::has_stylesheet_comments( $content ) ) {
				++$stats['css_with_comments'];
			} else {
				$issues[] = $rel . ': little/no section or file commenting';
			}
		}

		foreach ( $js_files as $file ) {
			$rel  = YWA_Helpers::rel_path( $file );
			$base = basename( $file );

			if ( self::is_minified_filename( $base ) ) {
				continue;
			}

			if ( ! self::is_valid_asset_filename( $base, 'js' ) ) {
				++$stats['js_bad_filenames'];
				$issues[] = $rel . ': invalid JS filename (use lowercase kebab-case)';
			}

			$content = YWA_Helpers::read_file( $file, 250000 );
			if ( '' === $content || self::looks_minified( $content ) ) {
				continue;
			}

			++$stats['js_checked'];
			$js_scan = self::scan_js_functions( $content, $rel );
			$stats['js_functions']      += $js_scan['functions'];
			$stats['js_functions_docs'] += $js_scan['functions_docs'];
			foreach ( array_slice( $js_scan['issues'], 0, 6 ) as $issue ) {
				$issues[] = $issue;
			}
		}

		$ratios = array();
		if ( $stats['php_functions'] > 0 ) {
			$ratios[] = $stats['php_functions_named'] / $stats['php_functions'];
			$ratios[] = $stats['php_functions_docs'] / $stats['php_functions'];
		}
		if ( $stats['php_hooks'] > 0 ) {
			$ratios[] = $stats['php_hooks_docs'] / $stats['php_hooks'];
		}
		if ( $stats['css_checked'] > 0 ) {
			$ratios[] = $stats['css_with_comments'] / $stats['css_checked'];
		}
		if ( $stats['js_functions'] > 0 ) {
			$ratios[] = $stats['js_functions_docs'] / $stats['js_functions'];
		}

		$file_total = $stats['php_files'] + $stats['css_files'] + $stats['js_files'];
		$bad_names  = $stats['php_bad_filenames'] + $stats['css_bad_filenames'] + $stats['js_bad_filenames'];
		$name_ratio = $file_total > 0 ? 1 - ( $bad_names / $file_total ) : 1;
		$doc_ratio  = ! empty( $ratios ) ? ( array_sum( $ratios ) / count( $ratios ) ) : 0;
		$combined   = ( $name_ratio * 0.4 ) + ( $doc_ratio * 0.6 );

		if ( $combined >= 0.75 && $bad_names <= 3 ) {
			$status = 'pass';
		} elseif ( $combined >= 0.45 ) {
			$status = 'warn';
		} else {
			$status = 'fail';
		}

		$message = sprintf(
			'Scanned active theme — PHP: %d, CSS/SCSS: %d, JS: %d. Functions documented: %d/%d · Hooks documented: %d/%d · CSS commented: %d/%d · JS functions documented: %d/%d · Bad filenames: %d.',
			$stats['php_files'],
			$stats['css_files'],
			$stats['js_files'],
			$stats['php_functions_docs'],
			$stats['php_functions'],
			$stats['php_hooks_docs'],
			$stats['php_hooks'],
			$stats['css_with_comments'],
			$stats['css_checked'],
			$stats['js_functions_docs'],
			$stats['js_functions'],
			$bad_names
		);

		$badges = array(
			array(
				'kind'  => 'metric',
				'label' => 'PHP docs',
				'value' => $stats['php_functions'] ? round( ( $stats['php_functions_docs'] / $stats['php_functions'] ) * 100 ) . '%' : 'n/a',
				'tone'  => self::ratio_tone( $stats['php_functions'] ? $stats['php_functions_docs'] / $stats['php_functions'] : 1 ),
			),
			array(
				'kind'  => 'metric',
				'label' => 'Hook docs',
				'value' => $stats['php_hooks'] ? round( ( $stats['php_hooks_docs'] / $stats['php_hooks'] ) * 100 ) . '%' : 'n/a',
				'tone'  => self::ratio_tone( $stats['php_hooks'] ? $stats['php_hooks_docs'] / $stats['php_hooks'] : 1 ),
			),
			array(
				'kind'  => 'metric',
				'label' => 'CSS comments',
				'value' => $stats['css_checked'] ? round( ( $stats['css_with_comments'] / $stats['css_checked'] ) * 100 ) . '%' : 'n/a',
				'tone'  => self::ratio_tone( $stats['css_checked'] ? $stats['css_with_comments'] / $stats['css_checked'] : 1 ),
			),
			array(
				'kind'  => 'metric',
				'label' => 'JS docs',
				'value' => $stats['js_functions'] ? round( ( $stats['js_functions_docs'] / $stats['js_functions'] ) * 100 ) . '%' : 'n/a',
				'tone'  => self::ratio_tone( $stats['js_functions'] ? $stats['js_functions_docs'] / $stats['js_functions'] : 1 ),
			),
			array(
				'kind'  => 'metric',
				'label' => 'Filenames',
				'value' => round( $name_ratio * 100 ) . '%',
				'tone'  => self::ratio_tone( $name_ratio ),
			),
		);

		// De-dupe and cap issues for popup list.
		$issues = array_values( array_unique( $issues ) );

		return YWA_Helpers::result(
			'open_random_files',
			$label,
			$status,
			$message,
			array_slice( $issues, 0, 100 ),
			1.3,
			array(
				'badges' => $badges,
			)
		);
	}

	/**
	 * @param float $ratio 0–1.
	 * @return string
	 */
	private static function ratio_tone( $ratio ) {
		if ( $ratio >= 0.75 ) {
			return 'pass';
		}
		if ( $ratio >= 0.45 ) {
			return 'warn';
		}
		return 'fail';
	}

	/**
	 * WordPress-friendly asset filenames: lowercase, kebab/snake, no spaces/camelCase.
	 *
	 * @param string $basename Filename.
	 * @param string $type php|css|js.
	 * @return bool
	 */
	private static function is_valid_asset_filename( $basename, $type ) {
		$basename = (string) $basename;
		if ( '' === $basename || false !== strpos( $basename, ' ' ) ) {
			return false;
		}
		// Allow WordPress core-ish templates and class-* / trait-* patterns.
		if ( 'php' === $type ) {
			return (bool) preg_match( '/^[a-z0-9][a-z0-9._-]*\.php$/', $basename );
		}
		if ( 'css' === $type ) {
			return (bool) preg_match( '/^[a-z0-9][a-z0-9._-]*\.(css|scss|sass)$/', $basename );
		}
		return (bool) preg_match( '/^[a-z0-9][a-z0-9._-]*\.js$/', $basename );
	}

	/**
	 * @param string $basename Filename.
	 * @return bool
	 */
	private static function is_minified_filename( $basename ) {
		return (bool) preg_match( '/\.min\.(js|css)$/i', $basename );
	}

	/**
	 * @param string $content File contents.
	 * @return bool
	 */
	private static function looks_minified( $content ) {
		$lines = substr_count( $content, "\n" );
		$len   = strlen( $content );
		return $len > 2000 && $lines < 5;
	}

	/**
	 * @param string $content CSS/SCSS contents.
	 * @return bool
	 */
	private static function has_stylesheet_comments( $content ) {
		if ( preg_match( '/\/\*[\s\S]{8,}?\*\//', $content ) ) {
			return true;
		}
		// SCSS/line comments for shorter utility files.
		$comment_lines = preg_match_all( '/^\s*\/\//m', $content );
		$line_count    = max( 1, substr_count( $content, "\n" ) );
		if ( $line_count < 40 && $comment_lines >= 1 ) {
			return true;
		}
		return $comment_lines >= 3;
	}

	/**
	 * Scan PHP functions + hooks for snake_case naming and preceding comments/docblocks.
	 *
	 * @param string $content PHP code.
	 * @param string $rel Relative path.
	 * @return array
	 */
	private static function scan_php_functions_and_hooks( $content, $rel ) {
		$out = array(
			'functions'       => 0,
			'functions_named' => 0,
			'functions_docs'  => 0,
			'hooks'           => 0,
			'hooks_docs'      => 0,
			'issues'          => array(),
		);

		$magic = array(
			'__construct',
			'__destruct',
			'__get',
			'__set',
			'__isset',
			'__unset',
			'__call',
			'__callStatic',
			'__toString',
			'__invoke',
			'__clone',
			'__wakeup',
			'__sleep',
		);

		if ( preg_match_all( '/function\s+&?\s*([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $match ) {
				$name   = $match[0];
				$offset = (int) $match[1];
				++$out['functions'];

				$is_magic = in_array( $name, $magic, true );
				$named_ok = $is_magic || (bool) preg_match( '/^[a-z][a-z0-9_]*$/', $name );
				if ( $named_ok ) {
					++$out['functions_named'];
				} else {
					$out['issues'][] = $rel . ': function `' . $name . '` should be snake_case';
				}

				if ( self::has_leading_comment( $content, $offset, 350 ) ) {
					++$out['functions_docs'];
				} else {
					$out['issues'][] = $rel . ': function `' . $name . '` missing docblock/comment';
				}
			}
		}

		if ( preg_match_all( '/add_(?:action|filter)\s*\(/i', $content, $hook_matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $hook_matches[0] as $match ) {
				$offset = (int) $match[1];
				++$out['hooks'];
				if ( self::has_leading_comment( $content, $offset, 250 ) ) {
					++$out['hooks_docs'];
				} else {
					// Extract hook name when possible for clearer evidence.
					$slice = substr( $content, $offset, 180 );
					$hook  = 'hook';
					if ( preg_match( '/add_(?:action|filter)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $slice, $hm ) ) {
						$hook = $hm[1];
					}
					$out['issues'][] = $rel . ': add_action/add_filter `' . $hook . '` missing preceding comment';
				}
			}
		}

		$out['issues'] = array_slice( $out['issues'], 0, 12 );
		return $out;
	}

	/**
	 * Scan JS functions for naming + JSDoc/block comments.
	 *
	 * @param string $content JS code.
	 * @param string $rel Relative path.
	 * @return array
	 */
	private static function scan_js_functions( $content, $rel ) {
		$out = array(
			'functions'      => 0,
			'functions_docs' => 0,
			'issues'         => array(),
		);

		$patterns = array(
			'/function\s+([A-Za-z_$][\w$]*)\s*\(/',
			'/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function\b|\([^)]*\)\s*=>|[A-Za-z_$][\w$]*\s*=>)/',
		);

		$seen = array();
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches[1] as $match ) {
				$name   = $match[0];
				$offset = (int) $match[1];
				$key    = $name . '@' . $offset;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				++$out['functions'];

				$named_ok = (bool) preg_match( '/^[a-z_$][a-zA-Z0-9_$]*$/', $name ) && ! preg_match( '/^[A-Z]{2,}/', $name );
				// Prefer camelCase for JS; flag SPACE or kebab in identifiers (rare).
				if ( ! $named_ok || false !== strpos( $name, '-' ) ) {
					$out['issues'][] = $rel . ': JS function `' . $name . '` has unusual naming';
				}

				if ( self::has_leading_comment( $content, $offset, 300 ) ) {
					++$out['functions_docs'];
				} else {
					$out['issues'][] = $rel . ': JS function `' . $name . '` missing JSDoc/comment';
				}
			}
		}

		// File-level comment credit for small scripts with no functions.
		if ( 0 === $out['functions'] && preg_match( '/\/\*\*|\/\*|\/\//', $content ) ) {
			// No function metrics; avoid punishing.
		}

		$out['issues'] = array_slice( $out['issues'], 0, 10 );
		return $out;
	}

	/**
	 * Whether a docblock or comment exists shortly before an offset.
	 *
	 * @param string $content Full file.
	 * @param int    $offset Offset of symbol.
	 * @param int    $lookback Bytes to inspect.
	 * @return bool
	 */
	private static function has_leading_comment( $content, $offset, $lookback = 300 ) {
		$start = max( 0, $offset - $lookback );
		$chunk = substr( $content, $start, $offset - $start );
		$chunk = rtrim( $chunk );

		// Strip trailing whitespace/newlines and optional attributes/visibility keywords on prior lines.
		if ( preg_match( '/\*\/\s*$/', $chunk ) ) {
			return true;
		}
		if ( preg_match( '/(?:^|\n)\s*\/\/[^\n]*\s*$/', $chunk ) ) {
			return true;
		}
		if ( preg_match( '/(?:^|\n)\s*#[^\n]*\s*$/', $chunk ) ) {
			return true;
		}
		// Docblock then modifiers: /** ... */ public static
		if ( preg_match( '/\*\/\s*(?:public|private|protected|static|final|abstract|\s)+$/i', $chunk ) ) {
			return true;
		}
		return false;
	}
}
