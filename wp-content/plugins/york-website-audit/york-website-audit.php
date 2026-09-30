<?php
/**
 * Plugin Name: York IE Website Audit
 * Description: Website audit tool that scores Code Quality, Performance, Security, Plugins, SEO, Documentation, CSS, CMS Admin, Backups, and Production Readiness on a 1–5 scale based on York IE audit criteria.
 * Version: 1.4.6
 * Author: York IE
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: york-website-audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YWA_VERSION', '1.4.6' );
define( 'YWA_PLUGIN_FILE', __FILE__ );
define( 'YWA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'YWA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'YWA_LIGHTHOUSE_API_KEY' ) ) {
	define( 'YWA_LIGHTHOUSE_API_KEY', 'AIzaSyDHKOI79Cc91NwtssBWtvV75U2JAS2MHEw' );
}

require_once YWA_PLUGIN_DIR . 'includes/class-ywa-categories.php';
require_once YWA_PLUGIN_DIR . 'includes/class-ywa-scorer.php';
require_once YWA_PLUGIN_DIR . 'includes/class-ywa-lighthouse.php';
require_once YWA_PLUGIN_DIR . 'includes/class-ywa-front-target.php';
require_once YWA_PLUGIN_DIR . 'includes/class-ywa-remote-client.php';
require_once YWA_PLUGIN_DIR . 'includes/class-ywa-auditor.php';
require_once YWA_PLUGIN_DIR . 'includes/class-ywa-pdf-report.php';
require_once YWA_PLUGIN_DIR . 'includes/class-ywa-excel-report.php';
require_once YWA_PLUGIN_DIR . 'includes/class-ywa-admin.php';

/**
 * Bootstrap plugin (admin + frontend panel shortcode).
 */
function ywa_init() {
	YWA_Front_Target::boot();
	YWA_Admin::instance();
}
add_action( 'plugins_loaded', 'ywa_init' );

/**
 * Activation: options + frontend panel page.
 */
function ywa_activate() {
	add_option( 'ywa_last_report', array() );

	$existing = get_page_by_path( 'website-audit' );
	if ( ! ( $existing instanceof WP_Post ) ) {
		$page_id = wp_insert_post(
			array(
				'post_title'   => 'Website Audit',
				'post_name'    => 'website-audit',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[york_website_audit]',
			),
			true
		);
		if ( ! is_wp_error( $page_id ) && $page_id ) {
			update_option( 'ywa_panel_page_id', (int) $page_id, false );
		}
	} else {
		update_option( 'ywa_panel_page_id', (int) $existing->ID, false );
	}
}
register_activation_hook( __FILE__, 'ywa_activate' );

/**
 * Ensure frontend panel page exists even if plugin was already active.
 */
function ywa_maybe_create_panel_page() {
	$page_id = (int) get_option( 'ywa_panel_page_id', 0 );
	if ( $page_id && get_post( $page_id ) ) {
		return;
	}
	$existing = get_page_by_path( 'website-audit' );
	if ( $existing instanceof WP_Post ) {
		update_option( 'ywa_panel_page_id', (int) $existing->ID, false );
		return;
	}
	$created = wp_insert_post(
		array(
			'post_title'   => 'Website Audit',
			'post_name'    => 'website-audit',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[york_website_audit]',
			'post_author'  => 1,
		),
		true
	);
	if ( ! is_wp_error( $created ) && $created ) {
		update_option( 'ywa_panel_page_id', (int) $created, false );
	}
}
add_action( 'init', 'ywa_maybe_create_panel_page', 30 );
