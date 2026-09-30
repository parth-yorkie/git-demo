<?php
/**
 * Minimal PDF writer (core Helvetica fonts) for audit reports.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_PDF {

	/** @var array<int, string> */
	private $pages = array();

	/** @var string */
	private $current = '';

	/** @var float */
	private $y = 0;

	/** @var float */
	private $left = 48;

	/** @var float */
	private $right = 564;

	/** @var float */
	private $top = 770;

	/** @var float */
	private $bottom = 50;

	/** @var float */
	private $page_w = 612;

	/** @var float */
	private $page_h = 792;

	/** @var int */
	private $page_num = 0;

	public function __construct() {
		$this->add_page();
	}

	/**
	 * @return void
	 */
	public function add_page() {
		if ( '' !== $this->current ) {
			$this->pages[] = $this->current;
		}
		$this->current = '';
		$this->y       = $this->top;
		++$this->page_num;
	}

	/**
	 * Ensure space; add page if needed.
	 *
	 * @param float $needed Points needed.
	 * @return void
	 */
	public function need( $needed ) {
		if ( ( $this->y - $needed ) < $this->bottom ) {
			$this->add_page();
		}
	}

	/**
	 * @param string $text Text.
	 * @param float  $size Font size.
	 * @param string $style ''|B.
	 * @param string $color R G B 0-1 string e.g. "0.08 0.16 0.22".
	 * @return void
	 */
	public function text_line( $text, $size = 11, $style = '', $color = '0.08 0.13 0.17' ) {
		$this->need( $size + 6 );
		$safe = $this->escape( $this->to_pdf_string( $text ) );
		$font = ( 'B' === $style ) ? 'F2' : 'F1';
		$this->current .= sprintf(
			"BT /%s %s Tf %s rg %s %s Td (%s) Tj ET\n",
			$font,
			$this->n( $size ),
			$color,
			$this->n( $this->left ),
			$this->n( $this->y ),
			$safe
		);
		$this->y -= ( $size + 5 );
	}

	/**
	 * Wrapped paragraph.
	 *
	 * @param string $text Text.
	 * @param float  $size Font size.
	 * @param string $style ''|B.
	 * @param string $color Color.
	 * @param float  $line_gap Extra gap.
	 * @return void
	 */
	public function paragraph( $text, $size = 10, $style = '', $color = '0.08 0.13 0.17', $line_gap = 3 ) {
		$width = $this->right - $this->left;
		$lines = $this->wrap( $this->to_pdf_string( $text ), $size, $width );
		foreach ( $lines as $line ) {
			$this->need( $size + $line_gap + 2 );
			$safe = $this->escape( $line );
			$font = ( 'B' === $style ) ? 'F2' : 'F1';
			$this->current .= sprintf(
				"BT /%s %s Tf %s rg %s %s Td (%s) Tj ET\n",
				$font,
				$this->n( $size ),
				$color,
				$this->n( $this->left ),
				$this->n( $this->y ),
				$safe
			);
			$this->y -= ( $size + $line_gap );
		}
	}

	/**
	 * Horizontal rule.
	 *
	 * @return void
	 */
	public function rule() {
		$this->need( 12 );
		$this->y -= 4;
		$this->current .= sprintf(
			"0.75 0.8 0.84 RG 0.6 w %s %s m %s %s l S\n",
			$this->n( $this->left ),
			$this->n( $this->y ),
			$this->n( $this->right ),
			$this->n( $this->y )
		);
		$this->y -= 10;
	}

	/**
	 * Vertical spacer.
	 *
	 * @param float $pts Points.
	 * @return void
	 */
	public function space( $pts = 8 ) {
		$this->y -= $pts;
		if ( $this->y < $this->bottom ) {
			$this->add_page();
		}
	}

	/**
	 * Check row: status + label + message.
	 *
	 * @param string $status Status.
	 * @param string $label Label.
	 * @param string $message Message.
	 * @return void
	 */
	public function check_row( $status, $label, $message ) {
		$status = strtoupper( (string) $status );
		$color  = '0.4 0.44 0.52';
		if ( 'PASS' === $status ) {
			$color = '0.02 0.46 0.28';
		} elseif ( 'WARN' === $status ) {
			$color = '0.71 0.28 0.03';
		} elseif ( 'FAIL' === $status ) {
			$color = '0.71 0.14 0.09';
		}

		$this->need( 28 );
		$safe_status = $this->escape( $this->to_pdf_string( $status ) );
		$safe_label  = $this->escape( $this->to_pdf_string( $label ) );

		$this->current .= sprintf(
			"BT /F2 8 Tf %s rg %s %s Td (%s) Tj ET\n",
			$color,
			$this->n( $this->left ),
			$this->n( $this->y ),
			$safe_status
		);
		$this->current .= sprintf(
			"BT /F2 9 Tf 0.08 0.13 0.17 rg %s %s Td (%s) Tj ET\n",
			$this->n( $this->left + 42 ),
			$this->n( $this->y ),
			$safe_label
		);
		$this->y -= 12;

		$this->paragraph( $message, 9, '', '0.36 0.42 0.47', 2 );
		$this->space( 4 );
	}

	/**
	 * Build binary PDF.
	 *
	 * @return string
	 */
	public function output() {
		if ( '' !== $this->current ) {
			$this->pages[] = $this->current;
			$this->current = '';
		}

		$objects = array();
		$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

		$page_count = count( $this->pages );
		$kids       = array();
		// Object numbers: 1=Catalog, 2=Pages, 3=F1, 4=F2, then page pairs.
		$first_page_obj = 5;
		for ( $i = 0; $i < $page_count; $i++ ) {
			$kids[] = ( $first_page_obj + ( $i * 2 ) ) . ' 0 R';
		}
		$objects[] = sprintf(
			'<< /Type /Pages /Kids [%s] /Count %d >>',
			implode( ' ', $kids ),
			$page_count
		);
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

		foreach ( $this->pages as $i => $content ) {
			// Footer page number.
			$footer  = sprintf(
				"BT /F1 8 Tf 0.45 0.5 0.55 rg %s 28 Td (%s) Tj ET\n",
				$this->n( $this->left ),
				$this->escape( 'York IE Website Audit  ·  Page ' . ( $i + 1 ) . ' of ' . $page_count )
			);
			$stream  = $content . $footer;
			$objects[] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
				$this->n( $this->page_w ),
				$this->n( $this->page_h ),
				$first_page_obj + ( $i * 2 ) + 1
			);
			$objects[] = sprintf(
				"<< /Length %d >>\nstream\n%s\nendstream",
				strlen( $stream ),
				$stream
			);
		}

		$pdf    = "%PDF-1.4\n";
		$offsets = array( 0 );
		foreach ( $objects as $idx => $obj ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $idx + 1 ) . " 0 obj\n" . $obj . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n";
		$pdf .= '0 ' . ( count( $objects ) + 1 ) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= count( $objects ); $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer\n";
		$pdf .= sprintf( "<< /Size %d /Root 1 0 R >>\n", count( $objects ) + 1 );
		$pdf .= "startxref\n" . $xref . "\n%%EOF";

		return $pdf;
	}

	/**
	 * @param float $n Number.
	 * @return string
	 */
	private function n( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Escape PDF string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function escape( $text ) {
		return str_replace(
			array( '\\', '(', ')', "\r" ),
			array( '\\\\', '\\(', '\\)', '' ),
			$text
		);
	}

	/**
	 * Convert to PDF-safe Latin string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function to_pdf_string( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text );
			if ( false !== $converted && '' !== $converted ) {
				return $converted;
			}
		}

		return preg_replace( '/[^\x20-\x7E]/', '?', $text );
	}

	/**
	 * Word-wrap using approximate Helvetica widths.
	 *
	 * @param string $text Text.
	 * @param float  $size Font size.
	 * @param float  $max_width Max width.
	 * @return string[]
	 */
	private function wrap( $text, $size, $max_width ) {
		if ( '' === $text ) {
			return array( '' );
		}
		$words = explode( ' ', $text );
		$lines = array();
		$line  = '';
		foreach ( $words as $word ) {
			$trial = ( '' === $line ) ? $word : ( $line . ' ' . $word );
			if ( $this->text_width( $trial, $size ) <= $max_width ) {
				$line = $trial;
			} else {
				if ( '' !== $line ) {
					$lines[] = $line;
				}
				// Hard-split very long tokens.
				while ( $this->text_width( $word, $size ) > $max_width && strlen( $word ) > 1 ) {
					$cut = max( 1, (int) floor( $max_width / ( $size * 0.5 ) ) );
					$lines[] = substr( $word, 0, $cut );
					$word    = substr( $word, $cut );
				}
				$line = $word;
			}
		}
		if ( '' !== $line ) {
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Approximate string width in points.
	 *
	 * @param string $text Text.
	 * @param float  $size Size.
	 * @return float
	 */
	private function text_width( $text, $size ) {
		return strlen( $text ) * $size * 0.5;
	}
}
