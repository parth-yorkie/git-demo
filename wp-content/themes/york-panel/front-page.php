<?php
/**
 * Homepage: login (logged out) or dashboard (logged in).
 *
 * @package York_Panel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

if ( is_user_logged_in() ) :
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
				<a class="yp-nav__link is-active" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Dashboard', 'york-panel' ); ?></a>
				<?php foreach ( $tools as $tool ) : ?>
					<a class="yp-nav__link" href="<?php echo esc_url( $tool['url'] ); ?>"><?php echo esc_html( $tool['title'] ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="yp-sidebar__foot">
				<p class="yp-user"><?php echo esc_html( $user->display_name ? $user->display_name : $user->user_login ); ?></p>
				<a class="yp-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'york-panel' ); ?></a>
			</div>
		</aside>

		<main class="yp-main">
			<header class="yp-main__head">
				<p class="yp-eyebrow"><?php esc_html_e( 'Admin panel', 'york-panel' ); ?></p>
				<h1><?php esc_html_e( 'Dashboard', 'york-panel' ); ?></h1>
				<p class="yp-lede">
					<?php
					printf(
						/* translators: %s: display name */
						esc_html__( 'Welcome back, %s. Choose a tool to continue.', 'york-panel' ),
						esc_html( $user->display_name ? $user->display_name : $user->user_login )
					);
					?>
				</p>
			</header>

			<?php if ( empty( $tools ) ) : ?>
				<section class="yp-empty">
					<p><?php esc_html_e( 'You are logged in, but no panel tools are available for your role.', 'york-panel' ); ?></p>
				</section>
			<?php else : ?>
				<section class="yp-grid" aria-label="<?php esc_attr_e( 'Tools', 'york-panel' ); ?>">
					<?php foreach ( $tools as $tool ) : ?>
						<article class="yp-card">
							<h2><?php echo esc_html( $tool['title'] ); ?></h2>
							<p><?php echo esc_html( $tool['desc'] ); ?></p>
							<a class="yp-btn" href="<?php echo esc_url( $tool['url'] ); ?>"><?php echo esc_html( $tool['cta'] ); ?></a>
						</article>
					<?php endforeach; ?>
				</section>
			<?php endif; ?>
		</main>
	</div>
	<?php
else :
	$error = york_panel_login_error_message();
	?>
	<div class="yp-login">
		<div class="yp-login__panel">
			<header class="yp-login__head">
				<p class="yp-eyebrow"><?php esc_html_e( 'York IE', 'york-panel' ); ?></p>
				<h1><?php bloginfo( 'name' ); ?></h1>
				<p class="yp-lede"><?php esc_html_e( 'Sign in to open the admin panel.', 'york-panel' ); ?></p>
			</header>

			<?php if ( $error ) : ?>
				<div class="yp-alert" role="alert"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<form class="yp-form" method="post" action="<?php echo esc_url( home_url( '/' ) ); ?>" autocomplete="on">
				<?php wp_nonce_field( 'york_panel_login' ); ?>
				<input type="hidden" name="york_panel_login" value="1" />

				<label class="yp-field">
					<span><?php esc_html_e( 'Username or email', 'york-panel' ); ?></span>
					<input type="text" name="log" id="user_login" required autocomplete="username" autofocus />
				</label>

				<label class="yp-field">
					<span><?php esc_html_e( 'Password', 'york-panel' ); ?></span>
					<input type="password" name="pwd" id="user_pass" required autocomplete="current-password" />
				</label>

				<label class="yp-check">
					<input type="checkbox" name="rememberme" value="forever" />
					<span><?php esc_html_e( 'Remember me', 'york-panel' ); ?></span>
				</label>

				<button type="submit" class="yp-btn yp-btn--block"><?php esc_html_e( 'Sign in', 'york-panel' ); ?></button>
			</form>

			<p class="yp-login__meta">
				<a href="<?php echo esc_url( wp_lostpassword_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Forgot password?', 'york-panel' ); ?></a>
			</p>
		</div>
	</div>
	<?php
endif;

get_footer();
