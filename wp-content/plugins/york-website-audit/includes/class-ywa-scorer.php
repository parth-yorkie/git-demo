<?php
/**
 * Maps check pass/fail/warn results to a 1–5 score using Possible Answer rubrics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Scorer {

	/**
	 * Score a category from individual check results.
	 *
	 * Each check result: status = pass | warn | fail | skip, weight optional.
	 *
	 * @param array $check_results List of check result arrays.
	 * @return array{score:int, label:string, ratio:float, passed:int, warned:int, failed:int, skipped:int}
	 */
	public static function score_category( array $check_results ) {
		$passed  = 0;
		$warned  = 0;
		$failed  = 0;
		$skipped = 0;
		$weight_pass = 0.0;
		$weight_total = 0.0;

		foreach ( $check_results as $result ) {
			$status = isset( $result['status'] ) ? $result['status'] : 'skip';
			$weight = isset( $result['weight'] ) ? (float) $result['weight'] : 1.0;

			if ( 'skip' === $status ) {
				++$skipped;
				continue;
			}

			$weight_total += $weight;

			if ( 'pass' === $status ) {
				++$passed;
				$weight_pass += $weight;
			} elseif ( 'warn' === $status ) {
				++$warned;
				$weight_pass += $weight * 0.5;
			} else {
				++$failed;
			}
		}

		if ( $weight_total <= 0 ) {
			return array(
				'score'   => 1,
				'label'   => self::label( 1 ),
				'ratio'   => 0.0,
				'passed'  => $passed,
				'warned'  => $warned,
				'failed'  => $failed,
				'skipped' => $skipped,
			);
		}

		$ratio = $weight_pass / $weight_total;
		$score = self::ratio_to_score( $ratio, $failed, $passed + $warned + $failed );

		return array(
			'score'   => $score,
			'label'   => self::label( $score ),
			'ratio'   => round( $ratio, 3 ),
			'passed'  => $passed,
			'warned'  => $warned,
			'failed'  => $failed,
			'skipped' => $skipped,
		);
	}

	/**
	 * Convert pass ratio + failure severity into 1–5.
	 *
	 * @param float $ratio Pass ratio 0–1.
	 * @param int   $failed Failed count.
	 * @param int   $evaluated Evaluated (non-skip) count.
	 * @return int
	 */
	private static function ratio_to_score( $ratio, $failed, $evaluated ) {
		$fail_rate = $evaluated > 0 ? ( $failed / $evaluated ) : 1;

		if ( $ratio >= 0.92 && $fail_rate <= 0.05 ) {
			return 5;
		}
		if ( $ratio >= 0.75 && $fail_rate <= 0.2 ) {
			return 4;
		}
		if ( $ratio >= 0.55 ) {
			return 3;
		}
		if ( $ratio >= 0.3 ) {
			return 2;
		}
		return 1;
	}

	/**
	 * @param int $score Score 1–5.
	 * @return string
	 */
	public static function label( $score ) {
		$labels = array(
			1 => 'Very Poor',
			2 => 'Needs Improvement',
			3 => 'Acceptable',
			4 => 'Good',
			5 => 'Excellent',
		);
		return isset( $labels[ $score ] ) ? $labels[ $score ] : 'Unknown';
	}

	/**
	 * Overall site score (sum of category scores out of categories × 5).
	 *
	 * @param array $category_scores Map of category_id => score int.
	 * @return array{score:int, max:int, percent:float, average:float}
	 */
	public static function overall( array $category_scores ) {
		$count = count( $category_scores );
		$max   = $count > 0 ? ( $count * 5 ) : 50;

		if ( empty( $category_scores ) ) {
			return array(
				'score'   => 0,
				'max'     => $max,
				'percent' => 0,
				'average' => 0,
			);
		}

		$sum = (int) array_sum( $category_scores );
		$avg = $sum / $count;

		return array(
			'score'   => $sum,
			'max'     => $max,
			'percent' => round( ( $sum / $max ) * 100, 1 ),
			'average' => round( $avg, 2 ),
		);
	}
}
