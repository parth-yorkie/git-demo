<?php
/**
 * Build an Excel (.xls SpreadsheetML) improvements report.
 * Includes only skip / warn / fail check points.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Excel_Report {

	/**
	 * Build .xls binary/string content from an audit report.
	 *
	 * @param array $report Audit report.
	 * @return string
	 */
	public static function build( array $report ) {
		$rows = self::improvement_rows( $report );
		return self::to_spreadsheet_ml( $rows );
	}

	/**
	 * Filename: site_name_improvements.xls
	 *
	 * @param array $report Audit report.
	 * @return string
	 */
	public static function filename( array $report ) {
		$site = isset( $report['site']['name'] ) ? (string) $report['site']['name'] : 'website';
		$slug = sanitize_title( $site );
		if ( '' === $slug ) {
			$slug = 'website';
		}
		// Keep underscores readable in the requested pattern.
		$slug = str_replace( '-', '_', $slug );
		return $slug . '_improvements.xls';
	}

	/**
	 * Collect improvement rows.
	 *
	 * @param array $report Report.
	 * @return array[]
	 */
	public static function improvement_rows( array $report ) {
		$rows = array();
		if ( empty( $report['categories'] ) || ! is_array( $report['categories'] ) ) {
			return $rows;
		}

		foreach ( $report['categories'] as $cat ) {
			if ( empty( $cat['checks'] ) || ! is_array( $cat['checks'] ) ) {
				continue;
			}
			$category = isset( $cat['title'] ) ? (string) $cat['title'] : '';
			foreach ( $cat['checks'] as $check ) {
				$status = isset( $check['status'] ) ? strtolower( (string) $check['status'] ) : '';
				if ( ! in_array( $status, array( 'skip', 'warn', 'fail' ), true ) ) {
					continue;
				}

				$rows[] = array(
					'category' => $category,
					'point'    => isset( $check['label'] ) ? (string) $check['label'] : '',
					'findings' => isset( $check['message'] ) ? (string) $check['message'] : '',
					'status'   => strtoupper( $status ),
					'suggestion' => self::suggestion_for_check( $check, $cat ),
					'note'     => self::note_for_check( $check ),
				);
			}
		}

		return $rows;
	}

	/**
	 * @param array $check Check.
	 * @param array $cat Category.
	 * @return string
	 */
	private static function suggestion_for_check( array $check, array $cat ) {
		if ( ! empty( $check['tips'] ) && is_array( $check['tips'] ) ) {
			$tips = array();
			foreach ( array_slice( $check['tips'], 0, 3 ) as $tip ) {
				if ( is_string( $tip ) && '' !== trim( $tip ) ) {
					$tips[] = trim( $tip );
				}
			}
			if ( ! empty( $tips ) ) {
				return implode( ' | ', $tips );
			}
		}

		if ( ! empty( $check['opportunity_groups'] ) && is_array( $check['opportunity_groups'] ) ) {
			$bits = array();
			foreach ( array_slice( $check['opportunity_groups'], 0, 4 ) as $group ) {
				if ( empty( $group['title'] ) ) {
					continue;
				}
				$line = (string) $group['title'];
				if ( ! empty( $group['display'] ) ) {
					$line .= ' (' . $group['display'] . ')';
				}
				$bits[] = $line;
			}
			if ( ! empty( $bits ) ) {
				return implode( ' | ', $bits );
			}
		}

		if ( ! empty( $cat['answer'] ) ) {
			return 'Address gaps to move beyond: ' . (string) $cat['answer'];
		}

		$status = isset( $check['status'] ) ? strtolower( (string) $check['status'] ) : '';
		if ( 'skip' === $status ) {
			return 'Complete this check manually and record the outcome.';
		}
		if ( 'warn' === $status ) {
			return 'Review and improve this item to reduce risk / raise the score.';
		}
		return 'Fix this failing check as a priority.';
	}

	/**
	 * @param array $check Check.
	 * @return string
	 */
	private static function note_for_check( array $check ) {
		$notes = array();
		if ( ! empty( $check['evidence'] ) && is_array( $check['evidence'] ) ) {
			foreach ( array_slice( $check['evidence'], 0, 8 ) as $line ) {
				if ( is_string( $line ) && '' !== trim( $line ) ) {
					$notes[] = trim( $line );
				} elseif ( ! is_string( $line ) ) {
					$notes[] = wp_json_encode( $line );
				}
			}
		}

		if ( ! empty( $check['links'] ) && is_array( $check['links'] ) ) {
			foreach ( $check['links'] as $link ) {
				if ( ! empty( $link['url'] ) ) {
					$notes[] = (string) $link['url'];
				}
			}
		}

		if ( ! empty( $check['opportunity_groups'] ) && is_array( $check['opportunity_groups'] ) ) {
			foreach ( array_slice( $check['opportunity_groups'], 0, 2 ) as $group ) {
				if ( empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
					continue;
				}
				foreach ( array_slice( $group['items'], 0, 3 ) as $item ) {
					if ( ! empty( $item['url_short'] ) ) {
						$notes[] = $item['url_short'];
					} elseif ( ! empty( $item['label'] ) ) {
						$notes[] = $item['label'];
					}
				}
			}
		}

		return implode( ' | ', array_unique( $notes ) );
	}

	/**
	 * Excel 2003 XML Spreadsheet (opens in Excel as .xls).
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	private static function to_spreadsheet_ml( array $rows ) {
		$headers = array(
			'Category',
			'How to Check Point',
			'Findings',
			'Status',
			'Suggestion',
			'Note',
		);

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
		$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
		$xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
		$xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
		$xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
		$xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
		$xml .= '<Styles>' . "\n";
		$xml .= '<Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#F2F4F7" ss:Pattern="Solid"/></Style>' . "\n";
		$xml .= '<Style ss:ID="Wrap"><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>' . "\n";
		$xml .= '</Styles>' . "\n";
		$xml .= '<Worksheet ss:Name="Improvements">' . "\n";
		$xml .= '<Table>' . "\n";
		$xml .= '<Column ss:Width="160"/><Column ss:Width="200"/><Column ss:Width="280"/><Column ss:Width="70"/><Column ss:Width="280"/><Column ss:Width="240"/>' . "\n";

		$xml .= '<Row>' . "\n";
		foreach ( $headers as $header ) {
			$xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . self::xml( $header ) . '</Data></Cell>' . "\n";
		}
		$xml .= '</Row>' . "\n";

		foreach ( $rows as $row ) {
			$xml .= '<Row>' . "\n";
			$xml .= '<Cell ss:StyleID="Wrap"><Data ss:Type="String">' . self::xml( $row['category'] ) . '</Data></Cell>' . "\n";
			$xml .= '<Cell ss:StyleID="Wrap"><Data ss:Type="String">' . self::xml( $row['point'] ) . '</Data></Cell>' . "\n";
			$xml .= '<Cell ss:StyleID="Wrap"><Data ss:Type="String">' . self::xml( $row['findings'] ) . '</Data></Cell>' . "\n";
			$xml .= '<Cell ss:StyleID="Wrap"><Data ss:Type="String">' . self::xml( $row['status'] ) . '</Data></Cell>' . "\n";
			$xml .= '<Cell ss:StyleID="Wrap"><Data ss:Type="String">' . self::xml( $row['suggestion'] ) . '</Data></Cell>' . "\n";
			$xml .= '<Cell ss:StyleID="Wrap"><Data ss:Type="String">' . self::xml( $row['note'] ) . '</Data></Cell>' . "\n";
			$xml .= '</Row>' . "\n";
		}

		$xml .= '</Table>' . "\n";
		$xml .= '</Worksheet>' . "\n";
		$xml .= '</Workbook>';

		return $xml;
	}

	/**
	 * Escape for SpreadsheetML.
	 *
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
