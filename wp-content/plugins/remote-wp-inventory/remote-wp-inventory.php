<?php
/**
 * Plugin Name: Remote WP Inventory
 * Description: Public frontend tool to scan a WordPress site (via Application Password) for active/inactive themes and plugins, with versions, update status, and risk notes.
 * Version: 1.3.0
 * Author: York IE
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: remote-wp-inventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RWI_VERSION', '1.3.0' );
define( 'RWI_PLUGIN_FILE', __FILE__ );
define( 'RWI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RWI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once RWI_PLUGIN_DIR . 'includes/class-rwi-scanner.php';
require_once RWI_PLUGIN_DIR . 'includes/class-rwi-excel-report.php';
require_once RWI_PLUGIN_DIR . 'includes/class-rwi-frontend.php';

/**
 * Boot.
 */
function rwi_boot() {
	RWI_Frontend::instance();
}
add_action( 'plugins_loaded', 'rwi_boot' );

/**
 * Create public page with shortcode on activate.
 */
function rwi_activate() {
	$existing = get_page_by_path( 'wp-inventory-scan' );
	if ( $existing instanceof WP_Post ) {
		return;
	}

	$page_id = wp_insert_post(
		array(
			'post_title'   => 'WP Inventory Scan',
			'post_name'    => 'wp-inventory-scan',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[remote_wp_inventory]',
		),
		true
	);

	if ( ! is_wp_error( $page_id ) && $page_id ) {
		update_option( 'rwi_page_id', (int) $page_id, false );
	}
}
register_activation_hook( __FILE__, 'rwi_activate' );
