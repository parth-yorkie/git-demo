<?php
/**
 * Shared helpers for audit checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Helpers {

	/**
	 * Whether the current audit targets a remote saved site (frontend dropdown).
	 *
	 * @return bool
	 */
	public static function is_remote_audit() {
		return class_exists( 'YWA_Front_Target' ) && YWA_Front_Target::active();
	}

	/**
	 * Home URL for the site under audit (remote target when active).
	 *
	 * @param string $path Path suffix.
	 * @return string
	 */
	public static function target_home_url( $path = '/' ) {
		if ( self::is_remote_audit() ) {
			$t = YWA_Front_Target::get();
			$base = isset( $t['url'] ) ? untrailingslashit( (string) $t['url'] ) : '';
			if ( $base ) {
				$path = '/' . ltrim( (string) $path, '/' );
				if ( '/' === $path ) {
					return trailingslashit( $base );
				}
				return $base . $path;
			}
		}
		return home_url( $path );
	}

	/**
	 * Skip every check in a category (remote audits cannot read local theme/plugin files).
	 *
	 * @param string $category_id Category id.
	 * @param string $message Optional message.
	 * @return array[]
	 */
	public static function skip_category_for_remote( $category_id, $message = '' ) {
		$meta = YWA_Categories::get( $category_id );
		if ( empty( $meta['checks'] ) || ! is_array( $meta['checks'] ) ) {
			return array();
		}
		if ( '' === $message ) {
			$message = __( 'Skipped for remote site audits — this check needs the target site’s filesystem, not this panel install.', 'york-website-audit' );
		}
		$out = array();
		foreach ( $meta['checks'] as $id => $label ) {
			$out[] = self::result( $id, $label, 'skip', $message, array(), 1.0 );
		}
		return $out;
	}

	/**
	 * Active theme stylesheet directory.
	 *
	 * @return string
	 */
	public static function theme_dir() {
		return get_stylesheet_directory();
	}

	/**
	 * Parent theme directory if child theme, else same as theme_dir.
	 *
	 * @return string
	 */
	public static function parent_theme_dir() {
		return get_template_directory();
	}

	/**
	 * Recursively find files matching extensions under a directory.
	 *
	 * @param string   $dir Directory.
	 * @param string[] $exts Extensions without dot.
	 * @param int      $limit Max files.
	 * @return string[]
	 */
	public static function find_files( $dir, array $exts, $limit = 80 ) {
		$found = array();
		if ( ! is_dir( $dir ) ) {
			return $found;
		}

		$skip = array( 'node_modules', 'vendor', '.git', 'dist', 'build', 'bower_components', '.sass-cache' );
		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iter as $file ) {
			/** @var SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = $file->getPathname();
			foreach ( $skip as $part ) {
				if ( false !== strpos( $path, DIRECTORY_SEPARATOR . $part . DIRECTORY_SEPARATOR ) ) {
					continue 2;
				}
			}
			$ext = strtolower( $file->getExtension() );
			if ( in_array( $ext, $exts, true ) ) {
				$found[] = $path;
				if ( count( $found ) >= $limit ) {
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Read a file safely with size cap.
	 *
	 * @param string $path Path.
	 * @param int    $max_bytes Max bytes.
	 * @return string
	 */
	public static function read_file( $path, $max_bytes = 200000 ) {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			return '';
		}
		return (string) file_get_contents( $path, false, null, 0, min( $size, $max_bytes ) );
	}

	/**
	 * Sample random subset of files.
	 *
	 * @param string[] $files Files.
	 * @param int      $n Count.
	 * @return string[]
	 */
	public static function sample( array $files, $n = 12 ) {
		if ( count( $files ) <= $n ) {
			return $files;
		}
		$keys = array_rand( $files, $n );
		if ( ! is_array( $keys ) ) {
			$keys = array( $keys );
		}
		$out = array();
		foreach ( $keys as $k ) {
			$out[] = $files[ $k ];
		}
		return $out;
	}

	/**
	 * Whether a plugin slug/path appears active.
	 *
	 * @param string[] $needles Substrings to match against plugin file paths.
	 * @return bool
	 */
	public static function has_active_plugin( array $needles ) {
		if ( self::is_remote_audit() && class_exists( 'YWA_Remote_Client' ) && YWA_Remote_Client::available() ) {
			$snap = YWA_Remote_Client::security_snapshot();
			if ( ! is_wp_error( $snap ) ) {
				return YWA_Remote_Client::has_active_plugin( $snap, $needles );
			}
			return false;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $plugins ) ) {
			return false;
		}
		foreach ( $plugins as $plugin ) {
			foreach ( $needles as $needle ) {
				if ( false !== stripos( $plugin, $needle ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * All installed plugins (active + inactive).
	 *
	 * @return array
	 */
	public static function get_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugins();
	}

	/**
	 * Fetch homepage HTML.
	 *
	 * @return string
	 */
	public static function homepage_html() {
		$response = wp_remote_get(
			self::target_home_url( '/' ),
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => false,
				'user-agent'  => 'YorkWebsiteAudit/' . YWA_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Build a standard check result.
	 *
	 * @param string $id Check id.
	 * @param string $label How-to-check label.
	 * @param string $status pass|warn|fail|skip.
	 * @param string $message Message.
	 * @param array  $evidence Optional evidence lines.
	 * @param float  $weight Weight.
	 * @param array  $extra Optional extras (badges, links, etc).
	 * @return array
	 */
	public static function result( $id, $label, $status, $message, array $evidence = array(), $weight = 1.0, array $extra = array() ) {
		return array_merge(
			array(
				'id'       => $id,
				'label'    => $label,
				'status'   => $status,
				'message'  => $message,
				'evidence' => $evidence,
				'weight'   => $weight,
			),
			$extra
		);
	}

	/**
	 * Relative path for display.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function rel_path( $path ) {
		$abspath = wp_normalize_path( ABSPATH );
		$norm    = wp_normalize_path( $path );
		if ( 0 === strpos( $norm, $abspath ) ) {
			return substr( $norm, strlen( $abspath ) );
		}
		return $norm;
	}
}
