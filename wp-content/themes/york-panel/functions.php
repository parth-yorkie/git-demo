<?php
/**
 * York Panel — minimal admin-panel theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YORK_PANEL_VERSION', '1.1.2' );

require_once get_template_directory() . '/includes/class-york-sites.php';

/**
 * Theme setup.
 */
function york_panel_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	remove_theme_support( 'widgets-block-editor' );
}
add_action( 'after_setup_theme', 'york_panel_setup' );

/**
 * Front assets.
 */
function york_panel_assets() {
	wp_enqueue_style(
		'york-panel-fonts',
		'https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Serif:wght@550;600&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'york-panel',
		get_template_directory_uri() . '/assets/css/theme.css',
		array( 'york-panel-fonts' ),
		YORK_PANEL_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'york_panel_assets' );

/**
 * Keep the front end panel-like: hide admin bar.
 */
add_filter( 'show_admin_bar', '__return_false' );

/**
 * Route default wp-login to the themed homepage login (except logout / lostpassword / reset).
 */
function york_panel_login_redirect() {
	if ( 'wp-login.php' !== $GLOBALS['pagenow'] ) {
		return;
	}

	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pass   = array( 'logout', 'lostpassword', 'retrievepassword', 'resetpass', 'rp', 'postpass' );
	if ( in_array( $action, $pass, true ) ) {
		return;
	}

	// Allow POST to wp-login.php to continue (some plugins rely on it).
	if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' ) ) {
		return;
	}

	wp_safe_redirect( home_url( '/?login=1' ) );
	exit;
}
add_action( 'login_init', 'york_panel_login_redirect' );

/**
 * Handle homepage login POST.
 */
function york_panel_handle_login() {
	if ( ! isset( $_POST['york_panel_login'] ) ) {
		return;
	}

	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'york_panel_login' ) ) {
		wp_safe_redirect( home_url( '/?login_error=nonce' ) );
		exit;
	}

	$creds = array(
		'user_login'    => isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '',
		'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		'remember'      => ! empty( $_POST['rememberme'] ),
	);

	$user = wp_signon( $creds, is_ssl() );
	if ( is_wp_error( $user ) ) {
		wp_safe_redirect( home_url( '/?login_error=invalid' ) );
		exit;
	}

	wp_safe_redirect( home_url( '/' ) );
	exit;
}
add_action( 'template_redirect', 'york_panel_handle_login', 1 );

/**
 * Dashboard tool cards.
 *
 * @return array[]
 */
function york_panel_tools() {
	$tools = array();

	if ( current_user_can( 'manage_options' ) ) {
		$inventory = get_page_by_path( 'wp-inventory-scan' );
		$tools[]   = array(
			'title' => __( 'WP Inventory Scan', 'york-panel' ),
			'desc'  => __( 'Scan a remote WordPress site for themes and plugins via Application Password.', 'york-panel' ),
			'url'   => $inventory instanceof WP_Post ? get_permalink( $inventory ) : home_url( '/wp-inventory-scan/' ),
			'cta'   => __( 'Open scanner', 'york-panel' ),
		);

		$audit   = get_page_by_path( 'website-audit' );
		$tools[] = array(
			'title' => __( 'Website Audit', 'york-panel' ),
			'desc'  => __( 'Run the York IE category audit against this WordPress install.', 'york-panel' ),
			'url'   => $audit instanceof WP_Post ? get_permalink( $audit ) : home_url( '/website-audit/' ),
			'cta'   => __( 'Open audit', 'york-panel' ),
		);

		$tools[] = array(
			'title' => __( 'Add Website', 'york-panel' ),
			'desc'  => __( 'Save site URL, username, and Application Password for use in tool dropdowns.', 'york-panel' ),
			'url'   => york_sites_add_url(),
			'cta'   => __( 'Manage websites', 'york-panel' ),
		);

		$tools[] = array(
			'title' => __( 'WordPress Admin', 'york-panel' ),
			'desc'  => __( 'Full site administration: pages, plugins, users, and settings.', 'york-panel' ),
			'url'   => admin_url(),
			'cta'   => __( 'Go to wp-admin', 'york-panel' ),
		);
	}

	/**
	 * Filter dashboard tools.
	 *
	 * @param array $tools Tools.
	 */
	return apply_filters( 'york_panel_tools', $tools );
}

/**
 * Login error message from query arg.
 *
 * @return string
 */
function york_panel_login_error_message() {
	if ( empty( $_GET['login_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '';
	}
	$code = sanitize_key( wp_unslash( $_GET['login_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'nonce' === $code ) {
		return __( 'Security check failed. Please try again.', 'york-panel' );
	}
	return __( 'Invalid username or password.', 'york-panel' );
}

/**
 * Whether a nav URL matches the current request.
 *
 * @param string $url URL.
 * @return bool
 */
function york_panel_is_current_url( $url ) {
	if ( ! $url ) {
		return false;
	}
	$current_path = wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_PATH );
	$target_path  = wp_parse_url( $url, PHP_URL_PATH );
	$current_path = untrailingslashit( $current_path ? $current_path : '/' );
	$target_path  = untrailingslashit( $target_path ? $target_path : '/' );
	if ( $current_path === $target_path ) {
		return true;
	}
	// Admin URLs: match by page query arg when present.
	$current_qs = array();
	$target_qs  = array();
	wp_parse_str( (string) wp_parse_url( home_url( add_query_arg( array() ) ), PHP_URL_QUERY ), $current_qs );
	wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $target_qs );
	if ( ! empty( $target_qs['page'] ) && ! empty( $current_qs['page'] ) ) {
		return $target_qs['page'] === $current_qs['page'];
	}
	return false;
}
