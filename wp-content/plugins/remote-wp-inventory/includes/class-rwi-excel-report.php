<?php
/**
 * Excel (.xls SpreadsheetML) audit-style inventory report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWI_Excel_Report {

	/**
	 * @param array $report Scan report.
	 * @return string SpreadsheetML.
	 */
	public static function build( array $report ) {
		$rows = self::rows( $report );
		return self::to_spreadsheet_ml( $rows );
	}

	/**
	 * @param array $report Report.
	 * @return string
	 */
	public static function filename( array $report ) {
		// Prefer WordPress site title, e.g. FreightWise_websites_audit_report.xls
		$name = ! empty( $report['site']['name'] ) ? (string) $report['site']['name'] : '';
		if ( '' === $name && ! empty( $report['site']['url'] ) ) {
			$parts = wp_parse_url( (string) $report['site']['url'] );
			$name  = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		}
		$slug = sanitize_title( $name ? $name : 'website' );
		$slug = str_replace( '-', '_', $slug );
		return $slug . '_websites_audit_report.xls';
	}

	/**
	 * Build sheet rows matching the FreightWise-style template.
	 *
	 * @param array $report Report.
	 * @return array[]
	 */
	public static function rows( array $report ) {
		$site     = isset( $report['site'] ) && is_array( $report['site'] ) ? $report['site'] : array();
		$plugins  = isset( $report['plugins'] ) && is_array( $report['plugins'] ) ? $report['plugins'] : array();
		$themes   = isset( $report['themes'] ) && is_array( $report['themes'] ) ? $report['themes'] : array();
		$summary  = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();
		$domain   = isset( $site['url'] ) ? (string) $site['url'] : '';
		$date     = gmdate( 'm/d/Y' );
		$wp_ver   = isset( $site['wp_version'] ) ? (string) $site['wp_version'] : '';
		$wp_latest = isset( $site['wp_latest'] ) ? (string) $site['wp_latest'] : '';
		$php_text = isset( $site['php_text'] ) ? (string) $site['php_text'] : '';
		$sql_text = isset( $site['sql_text'] ) ? (string) $site['sql_text'] : 'SQL server is up to date';
		$https_text = ! empty( $site['https'] )
			? 'HTTPS connection is active'
			: 'HTTPS connection is not active';

		$active_theme = isset( $site['active_theme'] ) && is_array( $site['active_theme'] ) ? $site['active_theme'] : null;
		if ( ! $active_theme ) {
			foreach ( $themes as $theme ) {
				if ( isset( $theme['status'] ) && 'active' === $theme['status'] ) {
					$active_theme = $theme;
					break;
				}
			}
		}
		$theme_desc = '-';
		if ( $active_theme ) {
			$theme_name = isset( $active_theme['name'] ) ? (string) $active_theme['name'] : '';
			$theme_ver  = isset( $active_theme['version'] ) ? (string) $active_theme['version'] : '';
			$theme_desc = $theme_ver ? sprintf( '%s (%s)', $theme_name, $theme_ver ) : $theme_name;
		}

		$total_plugins = isset( $summary['plugins_total'] ) ? (int) $summary['plugins_total'] : count( $plugins );
		$plugins_blurb = sprintf(
			'Site has total %d plugin%s. Inactive plugins are tempting targets for attackers. If you are not going to use a plugin, you should consider removing it.',
			$total_plugins,
			1 === $total_plugins ? '' : 's'
		);

		$inactive = array();
		$updates  = array();
		foreach ( $plugins as $plugin ) {
			if ( isset( $plugin['status'] ) && 'inactive' === $plugin['status'] ) {
				$inactive[] = $plugin;
			}
			if ( isset( $plugin['update'] ) && 'available' === $plugin['update'] ) {
				$updates[] = $plugin;
			}
		}

		$rows   = array();
		$rows[] = self::row( 'Domain', $domain, '', '', '' );
		$rows[] = self::blank();
		$rows[] = self::row( 'Date', $date, '', '', '' );
		$rows[] = self::row( 'Overall LOE', '', '', '', '' );
		$rows[] = self::blank();

		$wp_desc = $wp_ver ? ( 'Current Wordpress Version: ' . $wp_ver ) : 'WordPress version could not be determined';
		$wp_before = $wp_ver;
		$wp_after  = '';
		if ( $wp_ver && $wp_latest && version_compare( $wp_ver, $wp_latest, '<' ) ) {
			$wp_after = $wp_latest;
		} elseif ( $wp_ver ) {
			$wp_after = $wp_ver;
		}
		$rows[] = self::row( 'Wordpress', $wp_desc, $wp_before, $wp_after, '' );
		$rows[] = self::blank();

		$rows[] = self::row( 'Themes', $theme_desc, '', '', '' );
		$rows[] = self::blank();

		$rows[] = self::row( 'Plugins', $plugins_blurb, '', '', '' );
		$rows[] = self::blank();

		if ( empty( $inactive ) ) {
			$rows[] = self::row( 'Inactive Plugins', '-', '', '', '' );
		} else {
			$first = true;
			foreach ( $inactive as $plugin ) {
				$name = isset( $plugin['name'] ) ? (string) $plugin['name'] : '';
				$ver  = isset( $plugin['version'] ) ? (string) $plugin['version'] : '';
				$line = '- ' . $name . ( $ver ? ' (' . $ver . ')' : '' );
				$rows[] = self::row( $first ? 'Inactive Plugins' : '', $line, '', '', '' );
				$first  = false;
			}
		}
		$rows[] = self::blank();

		if ( empty( $updates ) ) {
			$rows[] = self::row( 'Plugin Updates', '-', '', '', '' );
		} else {
			$first = true;
			foreach ( $updates as $plugin ) {
				$name   = isset( $plugin['name'] ) ? (string) $plugin['name'] : '';
				$before = isset( $plugin['version'] ) ? (string) $plugin['version'] : '';
				$after  = isset( $plugin['latest'] ) ? (string) $plugin['latest'] : '';
				$rows[] = self::row(
					$first ? 'Plugin Updates' : '',
					'- ' . $name,
					$before,
					$after,
					''
				);
				$first = false;
			}
		}
		$rows[] = self::blank();

		if ( '' === $php_text ) {
			$php_text = 'PHP version could not be determined remotely';
		}
		$rows[] = self::row( 'PHP Version', $php_text, '', '', '' );
		$rows[] = self::blank();

		$rows[] = self::row( 'SQL Server', $sql_text, '', '', '' );
		$rows[] = self::blank();

		$rows[] = self::row( 'HTTPS Connection', $https_text, '', '', '' );

		return $rows;
	}

	/**
	 * @param string $topic Topic.
	 * @param string $description Description.
	 * @param string $before Before version.
	 * @param string $after After version.
	 * @param string $status Status.
	 * @return array
	 */
	private static function row( $topic, $description, $before, $after, $status ) {
		return array(
			'topic'       => (string) $topic,
			'description' => (string) $description,
			'before'      => (string) $before,
			'after'       => (string) $after,
			'status'      => (string) $status,
		);
	}

	/**
	 * @return array
	 */
	private static function blank() {
		return self::row( '', '', '', '', '' );
	}

	/**
	 * @param array $rows Rows.
	 * @return string
	 */
	private static function to_spreadsheet_ml( array $rows ) {
		$headers = array(
			'Topic',
			'Description',
			'Before Update Version',
			'After Update Version',
			'Status',
		);

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
		$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
		$xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
		$xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
		$xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
		$xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
		$xml .= '<Styles>' . "\n";
		$xml .= '<Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#FCE4D6" ss:Pattern="Solid"/></Style>' . "\n";
		$xml .= '<Style ss:ID="Topic"><Font ss:Bold="1"/><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>' . "\n";
		$xml .= '<Style ss:ID="Wrap"><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>' . "\n";
		$xml .= '<Style ss:ID="StatusOk"><Font ss:Color="#067647"/><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>' . "\n";
		$xml .= '<Style ss:ID="Alt"><Interior ss:Color="#DEEBF7" ss:Pattern="Solid"/><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>' . "\n";
		$xml .= '<Style ss:ID="AltTopic"><Font ss:Bold="1"/><Interior ss:Color="#DEEBF7" ss:Pattern="Solid"/><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>' . "\n";
		$xml .= '<Style ss:ID="AltStatus"><Font ss:Color="#067647"/><Interior ss:Color="#DEEBF7" ss:Pattern="Solid"/><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>' . "\n";
		$xml .= '</Styles>' . "\n";
		$xml .= '<Worksheet ss:Name="Audit Report">' . "\n";
		$xml .= '<Table>' . "\n";
		$xml .= '<Column ss:Width="140"/><Column ss:Width="420"/><Column ss:Width="130"/><Column ss:Width="130"/><Column ss:Width="120"/>' . "\n";

		$xml .= '<Row>' . "\n";
		foreach ( $headers as $header ) {
			$xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . self::xml( $header ) . '</Data></Cell>' . "\n";
		}
		$xml .= '</Row>' . "\n";

		$i = 0;
		foreach ( $rows as $row ) {
			$alt        = ( 0 === ( $i % 2 ) );
			$topic_style = $alt ? 'AltTopic' : 'Topic';
			$wrap_style  = $alt ? 'Alt' : 'Wrap';
			$status_style = $alt ? 'AltStatus' : 'StatusOk';
			$xml .= '<Row>' . "\n";
			$xml .= '<Cell ss:StyleID="' . $topic_style . '"><Data ss:Type="String">' . self::xml( $row['topic'] ) . '</Data></Cell>' . "\n";
			$xml .= '<Cell ss:StyleID="' . $wrap_style . '"><Data ss:Type="String">' . self::xml( $row['description'] ) . '</Data></Cell>' . "\n";
			$xml .= '<Cell ss:StyleID="' . $wrap_style . '"><Data ss:Type="String">' . self::xml( $row['before'] ) . '</Data></Cell>' . "\n";
			$xml .= '<Cell ss:StyleID="' . $wrap_style . '"><Data ss:Type="String">' . self::xml( $row['after'] ) . '</Data></Cell>' . "\n";
			$xml .= '<Cell ss:StyleID="' . $status_style . '"><Data ss:Type="String">' . self::xml( $row['status'] ) . '</Data></Cell>' . "\n";
			$xml .= '</Row>' . "\n";
			++$i;
		}

		$xml .= '</Table>' . "\n";
		$xml .= '</Worksheet>' . "\n";
		$xml .= '</Workbook>';

		return $xml;
	}

	/**
	 * @param string $value Value.
	 * @return string
	 */
	private static function xml( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}
