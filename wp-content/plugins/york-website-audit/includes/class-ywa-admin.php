<?php
/**
 * Admin UI for York Website Audit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Admin {

	/** @var self|null */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_front' ) );
		add_shortcode( 'york_website_audit', array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_ywa_run_audit', array( $this, 'handle_run' ) );
		add_action( 'admin_post_ywa_export_pdf', array( $this, 'handle_export_pdf' ) );
		add_action( 'admin_post_ywa_export_excel', array( $this, 'handle_export_excel' ) );
		add_action( 'wp_ajax_ywa_run_audit', array( $this, 'ajax_run' ) );
		add_action( 'wp_ajax_ywa_run_audit_step', array( $this, 'ajax_run_step' ) );
	}

	/**
	 * wp-admin Website Audit screen URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function admin_panel_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'york-website-audit' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Frontend panel URL (falls back to wp-admin screen).
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function panel_url( $args = array() ) {
		$page_id = (int) get_option( 'ywa_panel_page_id', 0 );
		if ( ! $page_id ) {
			$page = get_page_by_path( 'website-audit' );
			if ( $page instanceof WP_Post ) {
				$page_id = (int) $page->ID;
			}
		}
		if ( $page_id ) {
			return add_query_arg( $args, get_permalink( $page_id ) );
		}
		return self::admin_panel_url( $args );
	}

	/**
	 * Safe return URL after admin-post actions (backend vs frontend).
	 *
	 * @param array $args Query args to merge onto the return base.
	 * @return string
	 */
	public static function return_url( $args = array() ) {
		$posted = isset( $_POST['ywa_return'] ) ? esc_url_raw( wp_unslash( $_POST['ywa_return'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$base   = wp_validate_redirect( $posted, false );
		if ( ! $base ) {
			$ref = wp_get_referer();
			$base = $ref ? wp_validate_redirect( $ref, false ) : false;
		}
		if ( ! $base ) {
			$base = self::admin_panel_url();
		}
		return add_query_arg( $args, remove_query_arg( array( 'ran', 'exclude_error', 'pdf_error' ), $base ) );
	}

	/**
	 * Register top-level menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Website Audit', 'york-website-audit' ),
			__( 'Website Audit', 'york-website-audit' ),
			'manage_options',
			'york-website-audit',
			array( $this, 'render_page' ),
			'dashicons-clipboard',
			58
		);
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public function admin_enqueue( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'york-website-audit' !== $page && 'toplevel_page_york-website-audit' !== $hook ) {
			return;
		}
		$this->enqueue_assets( self::admin_panel_url( array( 'ran' => 1 ) ), false );
	}

	/**
	 * Enqueue on frontend when the audit shortcode page is viewed.
	 */
	public function maybe_enqueue_front() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( $post->post_content, 'york_website_audit' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->enqueue_assets( self::panel_url( array( 'ran' => 1 ) ), true );
	}

	/**
	 * Shared assets for admin + frontend panel.
	 *
	 * @param string $redirect Redirect after stepped audit.
	 * @param bool   $frontend Whether this is the frontend panel.
	 */
	private function enqueue_assets( $redirect, $frontend = false ) {
		wp_enqueue_style(
			'ywa-admin',
			YWA_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			YWA_VERSION
		);
		wp_enqueue_script(
			'ywa-admin',
			YWA_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			YWA_VERSION,
			true
		);
		$categories = array();
		foreach ( YWA_Categories::all() as $id => $meta ) {
			$categories[] = array(
				'id'    => $id,
				'title' => $meta['title'],
			);
		}

		wp_localize_script(
			'ywa-admin',
			'YWA',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'ywa_run_audit' ),
				'categories' => $categories,
				'redirect'   => $redirect,
				'frontend'   => (bool) $frontend,
				'i18n'       => array(
					'running'    => __( 'Running audit…', 'york-website-audit' ),
					'category'   => __( 'Auditing: %s', 'york-website-audit' ),
					'progress'   => __( '%s of %s · %s', 'york-website-audit' ),
					'complete'   => __( 'Audit completed. Reloading results…', 'york-website-audit' ),
					'failed'     => __( 'Audit failed. Please try again.', 'york-website-audit' ),
					'runLabel'   => __( 'Run full audit', 'york-website-audit' ),
					'runningBtn' => __( 'Running…', 'york-website-audit' ),
					'noneLeft'   => __( 'Select at least one category to audit (uncheck fewer exclusions).', 'york-website-audit' ),
					'targetRequired' => __( 'Please select a saved website.', 'york-website-audit' ),
				),
			)
		);
	}

	/**
	 * Frontend shortcode output.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p class="ywa-front-notice">' . esc_html__( 'Please sign in to run the Website Audit.', 'york-website-audit' ) . '</p>';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p class="ywa-front-notice">' . esc_html__( 'You do not have permission to run the Website Audit.', 'york-website-audit' ) . '</p>';
		}

		$this->enqueue_assets( self::panel_url( array( 'ran' => 1 ) ), true );

		ob_start();
		$this->render_page( true );
		return (string) ob_get_clean();
	}

	/**
	 * Handle classic form POST run.
	 */
	public function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'york-website-audit' ) );
		}
		check_admin_referer( 'ywa_run_audit' );
		$ids = $this->included_ids_from_request();
		if ( empty( $ids ) ) {
			wp_safe_redirect( self::return_url( array( 'exclude_error' => 1 ) ) );
			exit;
		}
		YWA_Auditor::run( $ids );
		wp_safe_redirect( self::return_url( array( 'ran' => 1 ) ) );
		exit;
	}

	/**
	 * AJAX run (full).
	 */
	public function ajax_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'ywa_run_audit', 'nonce' );
		$ids = $this->included_ids_from_request();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => 'No categories selected.' ), 400 );
		}
		$report = YWA_Auditor::run( $ids );
		wp_send_json_success( $report );
	}

	/**
	 * AJAX stepped run — one category per request for progress UI.
	 */
	public function ajax_run_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		check_ajax_referer( 'ywa_run_audit', 'nonce' );

		$ids = $this->queue_ids_from_request();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => 'No categories selected.' ), 400 );
		}

		$total = count( $ids );
		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : 0;

		if ( $index < 0 || $index >= $total ) {
			wp_send_json_error( array( 'message' => 'Invalid step index.' ), 400 );
		}

		$category_id = $ids[ $index ];
		if ( isset( $_POST['category_id'] ) ) {
			$posted = sanitize_key( wp_unslash( $_POST['category_id'] ) );
			if ( $posted && in_array( $posted, $ids, true ) ) {
				$category_id = $posted;
			}
		}

		// Frontend panel may send a remote target via saved site_id or manual URL fields.
		$is_front_target = ! empty( $_POST['site_id'] ) || ! empty( $_POST['target_url'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $is_front_target && 0 === $index ) {
			$loaded = YWA_Front_Target::load_from_request();
			if ( is_wp_error( $loaded ) ) {
				wp_send_json_error(
					array(
						'message' => $loaded->get_error_message(),
						'code'    => $loaded->get_error_code(),
					),
					400
				);
			}
		} elseif ( $is_front_target ) {
			// Re-load target on later steps so Lighthouse URL filter stays active.
			YWA_Front_Target::load_from_request();
		}

		$meta       = YWA_Categories::get( $category_id );
		$report_key = YWA_Auditor::report_option_key( $is_front_target );
		$report     = ( 0 === $index ) ? YWA_Auditor::blank_report() : get_option( $report_key, YWA_Auditor::blank_report() );
		if ( ! is_array( $report ) ) {
			$report = YWA_Auditor::blank_report();
		}

		if ( 0 === $index ) {
			$report['included_categories'] = $ids;
			if ( YWA_Front_Target::active() ) {
				$remote = YWA_Front_Target::site();
				if ( is_array( $remote ) ) {
					$report['site'] = $remote;
				}
				$report['target_mode'] = 'frontend_remote';
			}
		}

		$report  = YWA_Auditor::run_category( $category_id, $report, true );
		$done    = ( $index + 1 ) >= $total;
		$percent = (int) round( ( ( $index + 1 ) / $total ) * 100 );

		wp_send_json_success(
			array(
				'index'          => $index,
				'next_index'     => $index + 1,
				'total'          => $total,
				'percent'        => $percent,
				'done'           => $done,
				'category_id'    => $category_id,
				'category_title' => isset( $meta['title'] ) ? $meta['title'] : $category_id,
				'overall'        => isset( $report['overall'] ) ? $report['overall'] : array(),
			)
		);
	}

	/**
	 * Included category ids from exclude checkboxes (form POST).
	 *
	 * @return string[]
	 */
	private function included_ids_from_request() {
		$all      = YWA_Auditor::category_ids();
		$excluded = array();
		if ( isset( $_POST['ywa_exclude'] ) && is_array( $_POST['ywa_exclude'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['ywa_exclude'] ) as $id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$id = sanitize_key( $id );
				if ( $id && in_array( $id, $all, true ) ) {
					$excluded[] = $id;
				}
			}
		}
		return array_values( array_diff( $all, $excluded ) );
	}

	/**
	 * Ordered queue from stepped AJAX (included ids), falling back to exclude list.
	 *
	 * @return string[]
	 */
	private function queue_ids_from_request() {
		$all = YWA_Auditor::category_ids();
		if ( isset( $_POST['queue'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = sanitize_text_field( wp_unslash( $_POST['queue'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ids = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $id ) {
				$id = sanitize_key( $id );
				if ( $id && in_array( $id, $all, true ) && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			if ( ! empty( $ids ) ) {
				return $ids;
			}
		}
		return $this->included_ids_from_request();
	}

	/**
	 * Download last report as PDF.
	 */
	public function handle_export_pdf() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'york-website-audit' ) );
		}
		check_admin_referer( 'ywa_export_pdf' );

		$report = self::report_from_request();
		if ( empty( $report ) || empty( $report['categories'] ) ) {
			wp_safe_redirect( self::return_url( array( 'pdf_error' => 1 ) ) );
			exit;
		}

		$pdf      = YWA_PDF_Report::build( $report );
		$site     = isset( $report['site']['name'] ) ? $report['site']['name'] : 'website';
		$slug     = sanitize_title( $site );
		$filename = 'website-audit-' . ( $slug ? $slug . '-' : '' ) . gmdate( 'Y-m-d' ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF
		exit;
	}

	/**
	 * Download improvements Excel report (.xls) — skip/warn/fail only.
	 */
	public function handle_export_excel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'york-website-audit' ) );
		}
		check_admin_referer( 'ywa_export_excel' );

		$report = self::report_from_request();
		if ( empty( $report ) || empty( $report['categories'] ) ) {
			wp_safe_redirect( self::return_url( array( 'pdf_error' => 1 ) ) );
			exit;
		}

		$xls      = YWA_Excel_Report::build( $report );
		$filename = YWA_Excel_Report::filename( $report );

		nocache_headers();
		header( 'Content-Type: application/vnd.ms-excel; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $xls ) );
		echo $xls; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- spreadsheet XML
		exit;
	}

	/**
	 * Render admin page.
	 *
	 * @param bool $frontend Frontend panel mode (shows remote URL fields).
	 */
	/**
	 * Load report for export forms (admin vs frontend remote).
	 *
	 * @return array
	 */
	private static function report_from_request() {
		$context = isset( $_POST['ywa_context'] ) ? sanitize_key( wp_unslash( $_POST['ywa_context'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$remote  = ( 'front' === $context );
		return YWA_Auditor::get_report( $remote );
	}

	public function render_page( $frontend = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report         = YWA_Auditor::get_report( (bool) $frontend );
		$ran            = isset( $_GET['ran'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pdf_error      = isset( $_GET['pdf_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$exclude_error  = isset( $_GET['exclude_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_visible = $ran || $pdf_error || $exclude_error;
		$status_error   = $pdf_error || $exclude_error;
		?>
		<div class="wrap ywa-wrap<?php echo $frontend ? ' ywa-wrap--frontend' : ''; ?>">
			<header class="ywa-hero">
				<div class="ywa-hero__text">
					<p class="ywa-eyebrow"><?php esc_html_e( 'York IE · Website Team', 'york-website-audit' ); ?></p>
					<h1><?php esc_html_e( 'Website Audit', 'york-website-audit' ); ?></h1>
					<p class="ywa-lede">
						<?php
						if ( $frontend ) {
							esc_html_e( 'Enter the WordPress site to audit (URL + Application Password), then run categories below.', 'york-website-audit' );
						} else {
							esc_html_e( 'Scores each category out of 5 using the audit “How to Check” points and Possible Answer rubrics.', 'york-website-audit' );
						}
						?>
					</p>
				</div>
				<div class="ywa-hero__actions">
					<?php
					$return_url = $frontend ? self::panel_url() : self::admin_panel_url();
					$context    = $frontend ? 'front' : 'admin';
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ywa-run-form">
						<?php wp_nonce_field( 'ywa_run_audit' ); ?>
						<input type="hidden" name="action" value="ywa_run_audit" />
						<input type="hidden" name="ywa_return" value="<?php echo esc_url( $return_url ); ?>" />
						<input type="hidden" name="ywa_context" value="<?php echo esc_attr( $context ); ?>" />
						<button type="button" class="button button-primary button-hero ywa-run-btn" id="ywa-run-btn">
							<?php esc_html_e( 'Run full audit', 'york-website-audit' ); ?>
						</button>
					</form>
					<?php if ( ! empty( $report ) && ! empty( $report['categories'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'ywa_export_pdf' ); ?>
							<input type="hidden" name="action" value="ywa_export_pdf" />
							<input type="hidden" name="ywa_return" value="<?php echo esc_url( $return_url ); ?>" />
							<input type="hidden" name="ywa_context" value="<?php echo esc_attr( $context ); ?>" />
							<button type="submit" class="button button-hero"><?php esc_html_e( 'Download PDF report', 'york-website-audit' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'ywa_export_excel' ); ?>
							<input type="hidden" name="action" value="ywa_export_excel" />
							<input type="hidden" name="ywa_return" value="<?php echo esc_url( $return_url ); ?>" />
							<input type="hidden" name="ywa_context" value="<?php echo esc_attr( $context ); ?>" />
							<button type="submit" class="button button-hero"><?php esc_html_e( 'Download Excel Report', 'york-website-audit' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</header>

			<?php if ( $frontend ) : ?>
				<section class="ywa-target" aria-labelledby="ywa-target-title">
					<div class="ywa-target__head">
						<h2 id="ywa-target-title"><?php esc_html_e( 'Target website', 'york-website-audit' ); ?></h2>
						<p><?php esc_html_e( 'Choose a saved website. The report is labeled with this site, and Lighthouse/performance uses this URL.', 'york-website-audit' ); ?></p>
					</div>
					<?php if ( function_exists( 'york_sites_dropdown_html' ) ) : ?>
						<?php
						echo york_sites_dropdown_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
							array(
								'name'     => 'site_id',
								'id'       => 'ywa-site-id',
								'required' => true,
							)
						);
						?>
					<?php else : ?>
						<div class="ywa-target__grid">
							<label class="ywa-target__field">
								<span><?php esc_html_e( 'Website URL', 'york-website-audit' ); ?></span>
								<input type="text" id="ywa-target-url" name="target_url" form="ywa-run-form" placeholder="https://example.com" autocomplete="url" />
							</label>
							<label class="ywa-target__field">
								<span><?php esc_html_e( 'WordPress username', 'york-website-audit' ); ?></span>
								<input type="text" id="ywa-target-user" name="target_user" form="ywa-run-form" autocomplete="username" />
							</label>
							<label class="ywa-target__field">
								<span><?php esc_html_e( 'Application Password', 'york-website-audit' ); ?></span>
								<input type="password" id="ywa-target-pass" name="target_app_password" form="ywa-run-form" autocomplete="current-password" />
							</label>
						</div>
						<p class="ywa-target__hint">
							<?php esc_html_e( 'Activate the York Panel theme to manage saved websites globally.', 'york-website-audit' ); ?>
						</p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<details class="ywa-exclude">
				<summary class="ywa-exclude__summary">
					<span class="ywa-exclude__summary-title"><?php esc_html_e( 'Exclude categories', 'york-website-audit' ); ?></span>
					<span class="ywa-exclude__chevron" aria-hidden="true"></span>
				</summary>
				<div class="ywa-exclude__body">
					<p class="ywa-exclude__help"><?php esc_html_e( 'Check any categories to skip for this run. Leave all unchecked to audit everything.', 'york-website-audit' ); ?></p>
					<ul class="ywa-exclude__list">
						<?php foreach ( YWA_Categories::all() as $id => $meta ) : ?>
							<li>
								<label class="ywa-exclude__item" for="ywa-exclude-<?php echo esc_attr( $id ); ?>">
									<input
										type="checkbox"
										class="ywa-exclude__check"
										id="ywa-exclude-<?php echo esc_attr( $id ); ?>"
										name="ywa_exclude[]"
										value="<?php echo esc_attr( $id ); ?>"
										form="ywa-run-form"
									/>
									<span class="ywa-exclude__num"><?php echo esc_html( (string) (int) $meta['number'] ); ?></span>
									<span class="ywa-exclude__label"><?php echo esc_html( $meta['title'] ); ?></span>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</details>

			<div id="ywa-progress" class="ywa-progress" aria-hidden="true">
				<div class="ywa-progress__top">
					<span class="ywa-progress__pct" id="ywa-progress-pct">0%</span>
				</div>
				<div class="ywa-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="ywa-progress-track">
					<div class="ywa-progress__bar" id="ywa-progress-bar"></div>
				</div>
				<div class="ywa-progress__meta" id="ywa-progress-meta"></div>
			</div>

			<div id="ywa-status" class="ywa-status<?php echo $status_visible ? ' is-visible' : ''; ?><?php echo $status_error ? ' is-error' : ''; ?>" role="status">
				<?php
				if ( $pdf_error ) {
					esc_html_e( 'No audit report available to export. Run a full audit first.', 'york-website-audit' );
				} elseif ( $exclude_error ) {
					esc_html_e( 'Select at least one category to audit (uncheck fewer exclusions).', 'york-website-audit' );
				} elseif ( $ran ) {
					esc_html_e( 'Audit completed. Results below.', 'york-website-audit' );
				}
				?>
			</div>

			<?php if ( empty( $report ) || empty( $report['categories'] ) ) : ?>
				<section class="ywa-empty">
					<h2><?php esc_html_e( 'No report yet', 'york-website-audit' ); ?></h2>
					<p><?php esc_html_e( 'Run the audit to evaluate the selected categories against this WordPress site.', 'york-website-audit' ); ?></p>
				</section>
			<?php else : ?>
				<?php $this->render_report( $report ); ?>
			<?php endif; ?>

			<div id="ywa-modal" class="ywa-modal" hidden>
				<div class="ywa-modal__backdrop" data-ywa-modal-close></div>
				<div class="ywa-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ywa-modal-title">
					<header class="ywa-modal__header">
						<h2 id="ywa-modal-title"><?php esc_html_e( 'Affected files', 'york-website-audit' ); ?></h2>
						<button type="button" class="ywa-modal__close" data-ywa-modal-close aria-label="<?php esc_attr_e( 'Close', 'york-website-audit' ); ?>">&times;</button>
					</header>
					<div id="ywa-modal-body" class="ywa-modal__body"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array $report Report.
	 */
	private function render_report( array $report ) {
		// Always derive totals from category scores so older average-based reports update too.
		$score_map = array();
		if ( ! empty( $report['categories'] ) && is_array( $report['categories'] ) ) {
			foreach ( $report['categories'] as $id => $cat ) {
				if ( isset( $cat['score'] ) ) {
					$score_map[ $id ] = (int) $cat['score'];
				}
			}
		}
		$overall   = YWA_Scorer::overall( $score_map );
		$score_val = (int) $overall['score'];
		$score_max = (int) $overall['max'];
		$score_pct = max( 0, min( 100, ( $score_val / max( 1, $score_max ) ) * 100 ) );
		?>
		<section class="ywa-summary">
			<div class="ywa-score-ring" data-score="<?php echo esc_attr( (string) $score_val ); ?>" data-max="<?php echo esc_attr( (string) $score_max ); ?>" data-percent="<?php echo esc_attr( (string) $score_pct ); ?>">
				<svg class="ywa-score-ring__svg" viewBox="0 0 120 120" aria-hidden="true">
					<circle class="ywa-score-ring__track" cx="60" cy="60" r="54"></circle>
					<circle class="ywa-score-ring__progress" cx="60" cy="60" r="54"></circle>
				</svg>
				<div class="ywa-score-ring__content">
					<span class="ywa-score-ring__value" data-target="<?php echo esc_attr( (string) $score_val ); ?>">0</span>
					<span class="ywa-score-ring__max">/ <?php echo esc_html( (string) $score_max ); ?></span>
				</div>
			</div>
			<div class="ywa-summary__meta">
				<h2><?php esc_html_e( 'Overall score', 'york-website-audit' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: earned points, 2: max points, 3: percent, 4: site name, 5: datetime */
						esc_html__( '%1$s / %2$s points (%3$s%%) · %4$s · %5$s', 'york-website-audit' ),
						esc_html( (string) $score_val ),
						esc_html( (string) $score_max ),
						esc_html( (string) $overall['percent'] ),
						esc_html( isset( $report['site']['name'] ) ? $report['site']['name'] : '' ),
						esc_html( isset( $report['generated'] ) ? $report['generated'] : '' )
					);
					?>
				</p>
				<div class="ywa-scoreboard">
					<?php foreach ( $report['categories'] as $cat ) : ?>
						<a class="ywa-chip score-<?php echo esc_attr( (string) $cat['score'] ); ?>" href="#ywa-cat-<?php echo esc_attr( $cat['id'] ); ?>">
							<span class="ywa-chip__n"><?php echo esc_html( (string) $cat['score'] ); ?></span>
							<span class="ywa-chip__t"><?php echo esc_html( $cat['title'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</section>

		<div class="ywa-categories">
			<?php foreach ( $report['categories'] as $cat ) : ?>
				<details class="ywa-category" id="ywa-cat-<?php echo esc_attr( $cat['id'] ); ?>">
					<summary class="ywa-category__head">
						<div class="ywa-category__intro">
							<p class="ywa-category__num"><?php echo esc_html( sprintf( '#%d', $cat['number'] ) ); ?></p>
							<h3><?php echo esc_html( $cat['title'] ); ?></h3>
							<p class="ywa-category__q"><?php echo esc_html( $cat['question'] ); ?></p>
						</div>
						<div class="ywa-category__aside">
							<div class="ywa-category__score score-<?php echo esc_attr( (string) $cat['score'] ); ?>">
								<span class="ywa-category__score-n"><?php echo esc_html( (string) $cat['score'] ); ?></span>
								<span class="ywa-category__score-l"><?php echo esc_html( $cat['label'] ); ?></span>
							</div>
							<span class="ywa-category__chevron" aria-hidden="true"></span>
						</div>
					</summary>

					<div class="ywa-category__body">
						<p class="ywa-answer">
							<strong><?php esc_html_e( 'Matched answer', 'york-website-audit' ); ?>:</strong>
							<?php echo esc_html( $cat['answer'] ); ?>
						</p>

						<p class="ywa-stats">
							<?php
							$s = $cat['stats'];
							printf(
								/* translators: pass/warn/fail/skip counts */
								esc_html__( 'Checks — Pass: %1$d · Warn: %2$d · Fail: %3$d · Skip: %4$d', 'york-website-audit' ),
								(int) $s['passed'],
								(int) $s['warned'],
								(int) $s['failed'],
								(int) $s['skipped']
							);
							?>
						</p>

						<table class="widefat striped ywa-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'How to Check', 'york-website-audit' ); ?></th>
									<th><?php esc_html_e( 'Status', 'york-website-audit' ); ?></th>
									<th><?php esc_html_e( 'Finding', 'york-website-audit' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $cat['checks'] as $check ) : ?>
									<tr class="status-<?php echo esc_attr( $check['status'] ); ?>">
										<td class="ywa-check-label"><?php echo esc_html( $check['label'] ); ?></td>
										<td><span class="ywa-badge"><?php echo esc_html( strtoupper( $check['status'] ) ); ?></span></td>
										<td>
											<div class="ywa-finding"><?php echo esc_html( $check['message'] ); ?></div>
											<?php $this->render_check_details( $check ); ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render badges, links, tips, and evidence for a check.
	 *
	 * @param array $check Check result.
	 */
	private function render_check_details( array $check ) {
		if ( ! empty( $check['badge_groups'] ) && is_array( $check['badge_groups'] ) ) {
			foreach ( $check['badge_groups'] as $group ) {
				if ( empty( $group['badges'] ) || ! is_array( $group['badges'] ) ) {
					continue;
				}
				echo '<div class="ywa-badge-group">';
				if ( ! empty( $group['title'] ) ) {
					printf( '<h4 class="ywa-badge-group__title">%s</h4>', esc_html( (string) $group['title'] ) );
				}
				$this->render_badges( $group['badges'] );
				echo '</div>';
			}
		} elseif ( ! empty( $check['badges'] ) && is_array( $check['badges'] ) ) {
			$this->render_badges( $check['badges'] );
		}

		if ( ! empty( $check['links'] ) && is_array( $check['links'] ) ) {
			echo '<div class="ywa-check-links">';
			foreach ( $check['links'] as $link ) {
				if ( empty( $link['url'] ) ) {
					continue;
				}
				printf(
					'<a class="ywa-check-link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $link['url'] ),
					esc_html( ! empty( $link['label'] ) ? $link['label'] : $link['url'] )
				);
			}
			echo '</div>';
		}

		if ( ! empty( $check['opportunity_groups'] ) || ! empty( $check['tips'] ) ) {
			$panel_id = 'ywa-tips-' . sanitize_html_class( isset( $check['id'] ) ? $check['id'] : uniqid( 'tips_', false ) );
			$label    = ! empty( $check['tips_label'] ) ? $check['tips_label'] : __( 'How to improve?', 'york-website-audit' );
			$title    = __( 'How to improve Core Web Vitals', 'york-website-audit' );

			printf(
				'<button type="button" class="button ywa-tips-trigger" data-ywa-evidence-open="%1$s" data-ywa-evidence-title="%2$s" data-ywa-modal-wide="1">%3$s</button>',
				esc_attr( $panel_id ),
				esc_attr( $title ),
				esc_html( $label )
			);

			printf( '<div id="%s" class="ywa-evidence-panel" hidden>', esc_attr( $panel_id ) );
			if ( ! empty( $check['opportunity_groups'] ) && is_array( $check['opportunity_groups'] ) ) {
				$this->render_opportunity_groups( $check['opportunity_groups'] );
			} elseif ( ! empty( $check['tips'] ) && is_array( $check['tips'] ) ) {
				echo '<ol class="ywa-tips-list">';
				foreach ( $check['tips'] as $tip ) {
					echo '<li>' . esc_html( is_string( $tip ) ? $tip : wp_json_encode( $tip ) ) . '</li>';
				}
				echo '</ol>';
			}
			echo '</div>';
		}

		if ( ! empty( $check['evidence'] ) && is_array( $check['evidence'] ) ) {
			$count = count( $check['evidence'] );
			// Short evidence stays inline; longer lists open in a popup.
			if ( $count <= 2 ) {
				echo '<ul class="ywa-evidence">';
				foreach ( $check['evidence'] as $line ) {
					echo '<li>' . esc_html( is_string( $line ) ? $line : wp_json_encode( $line ) ) . '</li>';
				}
				echo '</ul>';
			} else {
				$panel_id = 'ywa-evidence-' . sanitize_html_class( isset( $check['id'] ) ? $check['id'] : uniqid( 'ev_', false ) );
				$title    = isset( $check['label'] ) ? $check['label'] : __( 'Affected files', 'york-website-audit' );

				printf(
					'<button type="button" class="ywa-evidence-trigger" data-ywa-evidence-open="%1$s" data-ywa-evidence-title="%2$s">%3$s</button>',
					esc_attr( $panel_id ),
					esc_attr( $title ),
					esc_html(
						sprintf(
							/* translators: %d: number of affected items */
							__( 'Show Affected Files (%d)', 'york-website-audit' ),
							$count
						)
					)
				);

				printf( '<div id="%s" class="ywa-evidence-panel" hidden>', esc_attr( $panel_id ) );
				echo '<ul class="ywa-evidence ywa-evidence--modal">';
				foreach ( $check['evidence'] as $line ) {
					echo '<li>' . esc_html( is_string( $line ) ? $line : wp_json_encode( $line ) ) . '</li>';
				}
				echo '</ul></div>';
			}
		}
	}

	/**
	 * Render site-specific Lighthouse opportunity groups (assets + savings).
	 *
	 * @param array $groups Opportunity groups.
	 */
	private function render_opportunity_groups( array $groups ) {
		echo '<div class="ywa-opportunities">';
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group['title'] ) ) {
				continue;
			}
			echo '<section class="ywa-opp">';
			echo '<header class="ywa-opp__head">';
			echo '<div class="ywa-opp__title-row">';
			echo '<span class="ywa-opp__icon" aria-hidden="true">!</span>';
			echo '<h3 class="ywa-opp__title">' . esc_html( $group['title'] );
			if ( ! empty( $group['display'] ) ) {
				echo ' <span class="ywa-opp__savings">' . esc_html( $group['display'] ) . '</span>';
			}
			echo '</h3>';
			echo '</div>';
			if ( ! empty( $group['description'] ) ) {
				echo '<p class="ywa-opp__desc">' . esc_html( wp_html_excerpt( $group['description'], 220, '…' ) ) . '</p>';
			}
			if ( ! empty( $group['metrics'] ) && is_array( $group['metrics'] ) ) {
				echo '<div class="ywa-opp__metrics">';
				foreach ( $group['metrics'] as $metric ) {
					echo '<span class="ywa-opp__metric">' . esc_html( (string) $metric ) . '</span>';
				}
				if ( ! empty( $group['strategy'] ) ) {
					echo '<span class="ywa-opp__metric ywa-opp__metric--muted">' . esc_html( ucfirst( (string) $group['strategy'] ) ) . '</span>';
				}
				echo '</div>';
			}
			echo '</header>';

			if ( ! empty( $group['items'] ) && is_array( $group['items'] ) ) {
				echo '<div class="ywa-opp__table">';
				echo '<div class="ywa-opp__row ywa-opp__row--head">';
				echo '<div>' . esc_html__( 'URL / asset', 'york-website-audit' ) . '</div>';
				echo '<div>' . esc_html__( 'Resource size', 'york-website-audit' ) . '</div>';
				echo '<div>' . esc_html__( 'Est savings', 'york-website-audit' ) . '</div>';
				echo '</div>';

				foreach ( $group['items'] as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					echo '<div class="ywa-opp__row">';
					echo '<div class="ywa-opp__asset">';
					echo '<div class="ywa-opp__asset-label">' . esc_html( isset( $item['label'] ) ? $item['label'] : '' ) . '</div>';
					if ( ! empty( $item['snippet'] ) ) {
						echo '<code class="ywa-opp__snippet">' . esc_html( $item['snippet'] ) . '</code>';
					}
					if ( ! empty( $item['url'] ) ) {
						printf(
							'<a class="ywa-opp__url" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
							esc_url( $item['url'] ),
							esc_html( ! empty( $item['url_short'] ) ? $item['url_short'] : $item['url'] )
						);
					}
					if ( ! empty( $item['reasons'] ) && is_array( $item['reasons'] ) ) {
						echo '<ul class="ywa-opp__reasons">';
						foreach ( $item['reasons'] as $reason ) {
							if ( ! is_array( $reason ) || empty( $reason['text'] ) ) {
								continue;
							}
							echo '<li>';
							echo '<span>' . esc_html( $reason['text'] ) . '</span>';
							if ( ! empty( $reason['savings'] ) ) {
								echo '<strong>' . esc_html( $reason['savings'] ) . '</strong>';
							}
							echo '</li>';
						}
						echo '</ul>';
					}
					echo '</div>';
					echo '<div class="ywa-opp__num">' . esc_html( isset( $item['size'] ) ? $item['size'] : '—' ) . '</div>';
					echo '<div class="ywa-opp__num ywa-opp__num--save">' . esc_html( isset( $item['savings'] ) && $item['savings'] ? $item['savings'] : '—' ) . '</div>';
					echo '</div>';
				}
				echo '</div>';
			}
			echo '</section>';
		}
		echo '</div>';
	}

	/**
	 * Render score pills + metric chips from a badge list.
	 *
	 * @param array $badges Badges.
	 */
	private function render_badges( array $badges ) {
		$score_badges  = array();
		$metric_badges = array();
		foreach ( $badges as $badge ) {
			if ( ! is_array( $badge ) || empty( $badge['label'] ) ) {
				continue;
			}
			if ( isset( $badge['kind'] ) && 'score' === $badge['kind'] ) {
				$score_badges[] = $badge;
			} else {
				$metric_badges[] = $badge;
			}
		}

		if ( ! empty( $score_badges ) ) {
			echo '<div class="ywa-score-badges">';
			foreach ( $score_badges as $badge ) {
				$tone = isset( $badge['tone'] ) ? $badge['tone'] : 'warn';
				printf(
					'<div class="ywa-score-pill tone-%1$s"><span class="ywa-score-pill__ring"><span class="ywa-score-pill__value">%2$s</span></span><span class="ywa-score-pill__label">%3$s</span></div>',
					esc_attr( $tone ),
					esc_html( isset( $badge['value'] ) ? (string) $badge['value'] : '—' ),
					esc_html( (string) $badge['label'] )
				);
			}
			echo '</div>';
		}

		if ( ! empty( $metric_badges ) ) {
			echo '<div class="ywa-metric-badges">';
			foreach ( $metric_badges as $badge ) {
				$tone = isset( $badge['tone'] ) ? $badge['tone'] : 'warn';
				printf(
					'<span class="ywa-metric-chip tone-%1$s"><span class="ywa-metric-chip__label">%2$s</span><span class="ywa-metric-chip__value">%3$s</span></span>',
					esc_attr( $tone ),
					esc_html( (string) $badge['label'] ),
					esc_html( isset( $badge['value'] ) ? (string) $badge['value'] : '—' )
				);
			}
			echo '</div>';
		}
	}
}
