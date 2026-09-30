<?php
/**
 * Public frontend shortcode + AJAX handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RWI_Frontend {

	/** @var self|null */
	private static $instance = null;

	/** @var bool */
	private $should_enqueue = false;

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
		add_shortcode( 'remote_wp_inventory', array( $this, 'render_shortcode' ) );
		add_action( 'wp', array( $this, 'detect_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_rwi_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_nopriv_rwi_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_rwi_scan_step', array( $this, 'ajax_scan_step' ) );
		add_action( 'wp_ajax_nopriv_rwi_scan_step', array( $this, 'ajax_scan_step' ) );
		add_action( 'wp_ajax_rwi_export_xls', array( $this, 'ajax_export_xls' ) );
		add_action( 'wp_ajax_nopriv_rwi_export_xls', array( $this, 'ajax_export_xls' ) );
	}

	/**
	 * Detect shortcode early so assets can load in head/footer correctly.
	 */
	public function detect_shortcode() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'remote_wp_inventory' ) ) {
			$this->should_enqueue = true;
		}
	}

	/**
	 * Enqueue CSS/JS when the inventory shortcode is present.
	 */
	public function enqueue_assets() {
		if ( ! $this->should_enqueue ) {
			return;
		}

		wp_enqueue_style(
			'rwi-fonts',
			'https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700&family=IBM+Plex+Serif:wght@600&display=swap',
			array(),
			null
		);
		wp_enqueue_style(
			'rwi-frontend',
			RWI_PLUGIN_URL . 'assets/css/frontend.css',
			array( 'rwi-fonts' ),
			RWI_VERSION
		);
		wp_enqueue_script(
			'rwi-frontend',
			RWI_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			RWI_VERSION,
			true
		);
		wp_localize_script(
			'rwi-frontend',
			'RWI',
			$this->script_config()
		);
	}

	/**
	 * @return array
	 */
	private function script_config() {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'rwi_scan' ),
				'i18n'    => array(
					'scanning' => __( 'Scanning site…', 'remote-wp-inventory' ),
					'failed'   => __( 'Scan failed. Please try again.', 'remote-wp-inventory' ),
					'required' => __( 'Please select a saved website.', 'remote-wp-inventory' ),
					'complete' => __( 'Scan complete.', 'remote-wp-inventory' ),
					'export'   => __( 'Download Excel Report', 'remote-wp-inventory' ),
					'exporting'=> __( 'Preparing Excel…', 'remote-wp-inventory' ),
					'exportFail' => __( 'Excel export failed. Please try again.', 'remote-wp-inventory' ),
				),
				'steps'   => array(
					array(
						'id'    => 'connect',
						'label' => __( 'Connect & authenticate', 'remote-wp-inventory' ),
					),
					array(
						'id'    => 'fetch_plugins',
						'label' => __( 'Fetch plugins', 'remote-wp-inventory' ),
					),
					array(
						'id'    => 'fetch_themes',
						'label' => __( 'Fetch themes', 'remote-wp-inventory' ),
					),
					array(
						'id'    => 'enrich_plugins',
						'label' => __( 'Check plugin updates', 'remote-wp-inventory' ),
					),
					array(
						'id'    => 'enrich_themes',
						'label' => __( 'Check theme updates', 'remote-wp-inventory' ),
					),
					array(
						'id'    => 'finalize',
						'label' => __( 'Build report', 'remote-wp-inventory' ),
					),
				),
		);
	}

	/**
	 * @return string
	 */
	public function render_shortcode() {
		// Ensure assets still load if detect_shortcode missed (e.g. shortcode in a widget/template).
		$this->should_enqueue = true;
		if ( ! wp_script_is( 'rwi-frontend', 'enqueued' ) ) {
			$this->enqueue_assets();
		}

		$config = wp_json_encode( $this->script_config() );

		ob_start();
		?>
		<div class="rwi-app" id="rwi-app" data-rwi-config="<?php echo esc_attr( $config ? $config : '{}' ); ?>">
			<header class="rwi-hero">
				<p class="rwi-eyebrow"><?php esc_html_e( 'WordPress inventory', 'remote-wp-inventory' ); ?></p>
				<h1 class="rwi-title"><?php esc_html_e( 'Remote theme & plugin scan', 'remote-wp-inventory' ); ?></h1>
				<p class="rwi-lede">
					<?php esc_html_e( 'Choose a saved website, then scan active/inactive themes and plugins, versions, update status, and risk notes.', 'remote-wp-inventory' ); ?>
				</p>
			</header>

			<form class="rwi-form" id="rwi-form" action="#" method="post" autocomplete="off" novalidate>
				<?php if ( function_exists( 'york_sites_dropdown_html' ) ) : ?>
					<?php
					echo york_sites_dropdown_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes.
						array(
							'name'     => 'site_id',
							'id'       => 'rwi-site-id',
							'required' => true,
						)
					);
					?>
				<?php else : ?>
					<label class="rwi-field">
						<span><?php esc_html_e( 'Website URL', 'remote-wp-inventory' ); ?></span>
						<input type="text" name="url" id="rwi-url" placeholder="https://example.com" required />
					</label>
					<div class="rwi-form__row">
						<label class="rwi-field">
							<span><?php esc_html_e( 'WordPress username', 'remote-wp-inventory' ); ?></span>
							<input type="text" name="username" id="rwi-username" autocomplete="username" required />
						</label>
						<label class="rwi-field">
							<span><?php esc_html_e( 'Application Password', 'remote-wp-inventory' ); ?></span>
							<input type="password" name="app_password" id="rwi-password" autocomplete="current-password" required />
						</label>
					</div>
					<p class="rwi-hint">
						<?php esc_html_e( 'Activate the York Panel theme to manage saved websites globally.', 'remote-wp-inventory' ); ?>
					</p>
				<?php endif; ?>
				<button type="button" class="rwi-submit" id="rwi-submit"><?php esc_html_e( 'Scan site', 'remote-wp-inventory' ); ?></button>
			</form>

			<div class="rwi-status" id="rwi-status" hidden></div>

			<div class="rwi-progress" id="rwi-progress" hidden aria-hidden="true">
				<div class="rwi-progress__top">
					<span class="rwi-progress__label" id="rwi-progress-label"><?php esc_html_e( 'Starting…', 'remote-wp-inventory' ); ?></span>
					<span class="rwi-progress__pct" id="rwi-progress-pct">0%</span>
				</div>
				<div class="rwi-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="rwi-progress-track">
					<div class="rwi-progress__bar" id="rwi-progress-bar"></div>
				</div>
				<p class="rwi-progress__detail" id="rwi-progress-detail"></p>
				<ol class="rwi-progress__steps" id="rwi-progress-steps"></ol>
			</div>

			<div class="rwi-results" id="rwi-results" hidden></div>
			<noscript>
				<p class="rwi-status is-error"><?php esc_html_e( 'JavaScript is required to run the scan.', 'remote-wp-inventory' ); ?></p>
			</noscript>
		</div>
		<script>
		window.RWI = window.RWI || <?php echo $config ? $config : '{}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode ?>;
		</script>
		<script src="<?php echo esc_url( RWI_PLUGIN_URL . 'assets/js/frontend.js?ver=' . rawurlencode( RWI_VERSION ) ); ?>" defer></script>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX stepped scan for realtime progress.
	 */
	public function ajax_scan_step() {
		if ( ! check_ajax_referer( 'rwi_scan', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Refresh the page and try again.', 'remote-wp-inventory' ),
				),
				403
			);
		}

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		if ( 'connect' === $step ) {
			$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			$key = 'rwi_rl_' . md5( $ip );
			if ( get_transient( $key ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Please wait a few seconds before scanning again.', 'remote-wp-inventory' ),
					),
					429
				);
			}
			set_transient( $key, 1, 15 );
		}

		@set_time_limit( 120 );

		$creds = $this->credentials_from_request();
		if ( is_wp_error( $creds ) ) {
			wp_send_json_error(
				array(
					'message' => $creds->get_error_message(),
					'code'    => $creds->get_error_code(),
				),
				400
			);
		}

		$result = RWI_Scanner::scan_step(
			array(
				'step'         => $step,
				'scan_id'      => isset( $_POST['scan_id'] ) ? sanitize_key( wp_unslash( $_POST['scan_id'] ) ) : '',
				'offset'       => isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0,
				'url'          => $creds['url'],
				'username'     => $creds['username'],
				'app_password' => $creds['app_password'],
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX scan handler (public) — full scan fallback.
	 */
	public function ajax_scan() {
		if ( ! check_ajax_referer( 'rwi_scan', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Refresh the page and try again.', 'remote-wp-inventory' ),
				),
				403
			);
		}

		// Light public abuse brake: one scan / 20s / IP.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'rwi_rl_' . md5( $ip );
		if ( get_transient( $key ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please wait a few seconds before scanning again.', 'remote-wp-inventory' ),
				),
				429
			);
		}
		set_transient( $key, 1, 20 );

		@set_time_limit( 300 );

		$creds = $this->credentials_from_request();
		if ( is_wp_error( $creds ) ) {
			wp_send_json_error(
				array(
					'message' => $creds->get_error_message(),
					'code'    => $creds->get_error_code(),
				),
				400
			);
		}

		$result = RWI_Scanner::scan( $creds['url'], $creds['username'], $creds['app_password'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Resolve credentials from saved site_id or manual POST fields.
	 *
	 * @return array{url:string,username:string,app_password:string}|WP_Error
	 */
	private function credentials_from_request() {
		$site_id = isset( $_POST['site_id'] ) ? sanitize_key( wp_unslash( $_POST['site_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $site_id && function_exists( 'york_sites_get' ) ) {
			$site = york_sites_get( $site_id );
			if ( ! $site ) {
				return new WP_Error( 'rwi_site', __( 'Saved website not found. Add it again from Add Website.', 'remote-wp-inventory' ) );
			}
			return array(
				'url'          => isset( $site['url'] ) ? (string) $site['url'] : '',
				'username'     => isset( $site['username'] ) ? (string) $site['username'] : '',
				'app_password' => isset( $site['app_password'] ) ? (string) $site['app_password'] : '',
			);
		}

		return array(
			'url'          => isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'username'     => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'app_password' => isset( $_POST['app_password'] ) ? (string) wp_unslash( $_POST['app_password'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
	}

	/**
	 * Build and return Excel report for the posted scan JSON.
	 */
	public function ajax_export_xls() {
		if ( ! check_ajax_referer( 'rwi_scan', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Refresh the page and try again.', 'remote-wp-inventory' ),
				),
				403
			);
		}

		$raw = isset( $_POST['report'] ) ? wp_unslash( $_POST['report'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_array( $raw ) ) {
			$report = $raw;
		} else {
			$report = json_decode( (string) $raw, true );
		}

		if ( ! is_array( $report ) || ( empty( $report['plugins'] ) && empty( $report['themes'] ) && empty( $report['site'] ) ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No scan report available to export. Run a scan first.', 'remote-wp-inventory' ),
				),
				400
			);
		}

		$xls      = RWI_Excel_Report::build( $report );
		$filename = RWI_Excel_Report::filename( $report );

		wp_send_json_success(
			array(
				'filename' => $filename,
				'mime'     => 'application/vnd.ms-excel',
				'content'  => base64_encode( $xls ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);
	}
}
