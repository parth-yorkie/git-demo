<?php
/**
 * Build a downloadable PDF from an audit report array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once YWA_PLUGIN_DIR . 'includes/class-ywa-pdf.php';

class YWA_PDF_Report {

	/**
	 * @param array $report Audit report.
	 * @return string PDF binary.
	 */
	public static function build( array $report ) {
		$pdf     = new YWA_PDF();
		$site    = isset( $report['site'] ) ? $report['site'] : array();
		$score_map = array();
		if ( ! empty( $report['categories'] ) && is_array( $report['categories'] ) ) {
			foreach ( $report['categories'] as $id => $cat ) {
				if ( isset( $cat['score'] ) ) {
					$score_map[ $id ] = (int) $cat['score'];
				}
			}
		}
		$overall = YWA_Scorer::overall( $score_map );
		$name    = isset( $site['name'] ) ? $site['name'] : 'Website';
		$url     = isset( $site['url'] ) ? $site['url'] : '';
		$when    = isset( $report['generated'] ) ? $report['generated'] : gmdate( 'Y-m-d H:i:s' );
		$earned  = (int) $overall['score'];
		$max     = (int) $overall['max'];

		$pdf->text_line( 'York IE  ·  Website Team', 10, 'B', '0.04 0.43 0.42' );
		$pdf->text_line( 'Website Audit Report', 20, 'B' );
		$pdf->paragraph( $name . ( $url ? '  —  ' . $url : '' ), 11, '', '0.36 0.42 0.47' );
		$pdf->paragraph( 'Generated: ' . $when, 9, '', '0.36 0.42 0.47' );
		$pdf->space( 6 );
		$pdf->rule();

		$pdf->text_line(
			'Overall score: ' . $earned . ' / ' . $max . ' points  (' . (string) $overall['percent'] . '% of max)',
			13,
			'B'
		);
		$cat_count = max( 1, (int) round( $max / 5 ) );
		$pdf->paragraph(
			'Each category is scored 1-5. Overall is the sum of category scores (' . $cat_count . ' categories x 5 = ' . $max . ' max).',
			9,
			'',
			'0.36 0.42 0.47'
		);
		$pdf->space( 8 );

		$pdf->text_line( 'Scoreboard', 12, 'B' );
		$pdf->space( 2 );
		if ( ! empty( $report['categories'] ) && is_array( $report['categories'] ) ) {
			foreach ( $report['categories'] as $cat ) {
				$line = sprintf(
					'#%d  %s  —  %s/5  (%s)',
					isset( $cat['number'] ) ? (int) $cat['number'] : 0,
					isset( $cat['title'] ) ? $cat['title'] : '',
					isset( $cat['score'] ) ? (string) $cat['score'] : '0',
					isset( $cat['label'] ) ? $cat['label'] : ''
				);
				$pdf->paragraph( $line, 10 );
			}
		}

		$pdf->space( 6 );
		$pdf->rule();

		if ( ! empty( $report['categories'] ) && is_array( $report['categories'] ) ) {
			foreach ( $report['categories'] as $cat ) {
				$pdf->need( 80 );
				$pdf->text_line(
					sprintf(
						'#%d  %s',
						isset( $cat['number'] ) ? (int) $cat['number'] : 0,
						isset( $cat['title'] ) ? $cat['title'] : ''
					),
					13,
					'B'
				);
				$pdf->text_line(
					sprintf(
						'Score: %s/5  —  %s',
						isset( $cat['score'] ) ? (string) $cat['score'] : '0',
						isset( $cat['label'] ) ? $cat['label'] : ''
					),
					11,
					'B',
					'0.04 0.43 0.42'
				);

				if ( ! empty( $cat['question'] ) ) {
					$pdf->paragraph( 'Question: ' . $cat['question'], 9, '', '0.36 0.42 0.47' );
				}
				if ( ! empty( $cat['answer'] ) ) {
					$pdf->paragraph( 'Matched answer: ' . $cat['answer'], 9 );
				}

				if ( ! empty( $cat['stats'] ) && is_array( $cat['stats'] ) ) {
					$s = $cat['stats'];
					$pdf->paragraph(
						sprintf(
							'Checks — Pass: %d  ·  Warn: %d  ·  Fail: %d  ·  Skip: %d',
							isset( $s['passed'] ) ? (int) $s['passed'] : 0,
							isset( $s['warned'] ) ? (int) $s['warned'] : 0,
							isset( $s['failed'] ) ? (int) $s['failed'] : 0,
							isset( $s['skipped'] ) ? (int) $s['skipped'] : 0
						),
						9,
						'',
						'0.36 0.42 0.47'
					);
				}

				$pdf->space( 4 );
				$pdf->text_line( 'How to Check findings', 10, 'B' );
				$pdf->space( 2 );

				if ( ! empty( $cat['checks'] ) && is_array( $cat['checks'] ) ) {
					foreach ( $cat['checks'] as $check ) {
						$msg = isset( $check['message'] ) ? $check['message'] : '';
						$badge_lists = array();
						if ( ! empty( $check['badge_groups'] ) && is_array( $check['badge_groups'] ) ) {
							foreach ( $check['badge_groups'] as $group ) {
								if ( empty( $group['badges'] ) || ! is_array( $group['badges'] ) ) {
									continue;
								}
								$group_bits = array();
								foreach ( $group['badges'] as $badge ) {
									if ( ! is_array( $badge ) || empty( $badge['label'] ) ) {
										continue;
									}
									$group_bits[] = $badge['label'] . ' ' . ( isset( $badge['value'] ) ? $badge['value'] : '' );
								}
								if ( ! empty( $group_bits ) ) {
									$prefix = ! empty( $group['title'] ) ? $group['title'] . ': ' : '';
									$badge_lists[] = $prefix . implode( ', ', $group_bits );
								}
							}
						} elseif ( ! empty( $check['badges'] ) && is_array( $check['badges'] ) ) {
							$bits = array();
							foreach ( $check['badges'] as $badge ) {
								if ( ! is_array( $badge ) || empty( $badge['label'] ) ) {
									continue;
								}
								$bits[] = $badge['label'] . ' ' . ( isset( $badge['value'] ) ? $badge['value'] : '' );
							}
							if ( ! empty( $bits ) ) {
								$badge_lists[] = implode( ' · ', $bits );
							}
						}
						if ( ! empty( $badge_lists ) ) {
							$msg .= ' | ' . implode( ' || ', $badge_lists );
						}
						if ( ! empty( $check['opportunity_groups'] ) && is_array( $check['opportunity_groups'] ) ) {
							$opp_bits = array();
							foreach ( array_slice( $check['opportunity_groups'], 0, 5 ) as $group ) {
								if ( empty( $group['title'] ) ) {
									continue;
								}
								$line = $group['title'];
								if ( ! empty( $group['display'] ) ) {
									$line .= ' (' . $group['display'] . ')';
								}
								if ( ! empty( $group['items'][0]['url_short'] ) ) {
									$line .= ' e.g. ' . $group['items'][0]['url_short'];
								}
								$opp_bits[] = $line;
							}
							if ( ! empty( $opp_bits ) ) {
								$msg .= ' | Opportunities: ' . implode( '; ', $opp_bits );
							}
						}
						if ( ! empty( $check['evidence'] ) && is_array( $check['evidence'] ) ) {
							$ev = array();
							foreach ( array_slice( $check['evidence'], 0, 4 ) as $line ) {
								$ev[] = is_string( $line ) ? $line : wp_json_encode( $line );
							}
							if ( ! empty( $ev ) ) {
								$msg .= ' | ' . implode( '; ', $ev );
							}
						}
						$pdf->check_row(
							isset( $check['status'] ) ? $check['status'] : 'skip',
							isset( $check['label'] ) ? $check['label'] : '',
							$msg
						);
					}
				}

				$pdf->space( 4 );
				$pdf->rule();
			}
		}

		$pdf->paragraph(
			'Skipped checks (manual review) do not lower category scores. Lighthouse runs via Google API against a publicly reachable URL. Confirm licensing and offsite backups manually where noted.',
			8,
			'',
			'0.45 0.5 0.55'
		);

		return $pdf->output();
	}
}
