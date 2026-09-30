<?php
/**
 * Page template with panel chrome when logged in.
 *
 * @package York_Panel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! is_user_logged_in() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

get_header();
$user  = wp_get_current_user();
$tools = york_panel_tools();
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
		<nav class="yp-nav" aria-label="<?php esc_attr_e( 'Panel', 'york-panel' ); ?>">
			<a class="yp-nav__link" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Dashboard', 'york-panel' ); ?></a>
			<?php foreach ( $tools as $tool ) : ?>
				<a class="yp-nav__link<?php echo york_panel_is_current_url( $tool['url'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $tool['url'] ); ?>">
					<?php echo esc_html( $tool['title'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<div class="yp-sidebar__foot">
			<p class="yp-user"><?php echo esc_html( $user->display_name ? $user->display_name : $user->user_login ); ?></p>
			<a class="yp-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'york-panel' ); ?></a>
		</div>
	</aside>
	<main class="yp-main yp-main--content">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<article <?php post_class( 'yp-content' ); ?>>
				<header class="yp-main__head">
					<p class="yp-eyebrow"><?php esc_html_e( 'Tool', 'york-panel' ); ?></p>
					<h1><?php the_title(); ?></h1>
				</header>
				<div class="yp-content__body">
					<?php the_content(); ?>
				</div>
			</article>
			<?php
		endwhile;
		?>
	</main>
</div>
<?php
get_footer();
