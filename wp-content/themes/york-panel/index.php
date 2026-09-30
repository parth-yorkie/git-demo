<?php
/**
 * Fallback — redirect into the panel.
 *
 * @package York_Panel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_user_logged_in() ) {
	// Rare non-page views: send to dashboard.
	get_header();
	?>
	<div class="yp-shell">
		<aside class="yp-sidebar">
			<div class="yp-brand">
				<span class="yp-brand__mark">Y</span>
				<div>
					<p class="yp-brand__eyebrow"><?php esc_html_e( 'York IE', 'york-panel' ); ?></p>
					<p class="yp-brand__name"><?php bloginfo( 'name' ); ?></p>
				</div>
			</div>
			<nav class="yp-nav">
				<a class="yp-nav__link is-active" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Dashboard', 'york-panel' ); ?></a>
			</nav>
		</aside>
		<main class="yp-main">
			<p><a class="yp-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to dashboard', 'york-panel' ); ?></a></p>
		</main>
	</div>
	<?php
	get_footer();
} else {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}
