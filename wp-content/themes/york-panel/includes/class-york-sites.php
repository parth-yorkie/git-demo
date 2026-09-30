<?php
/**
 * Global saved websites (URL + username + app password) for panel tools.
 *
 * @package York_Panel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option key.
 */
function york_sites_option_key() {
	return 'york_saved_sites';
}

/**
 * @return array[]
 */
function york_sites_all() {
	$sites = get_option( york_sites_option_key(), array() );
	return is_array( $sites ) ? array_values( $sites ) : array();
}

/**
 * Public list for dropdowns (no passwords).
 *
 * @return array[]
 */
function york_sites_choices() {
	$out = array();
	foreach ( york_sites_all() as $site ) {
		if ( empty( $site['id'] ) || empty( $site['url'] ) ) {
			continue;
		}
		$label = ! empty( $site['label'] ) ? (string) $site['label'] : (string) $site['url'];
		$out[] = array(
			'id'       => (string) $site['id'],
			'label'    => $label,
			'url'      => (string) $site['url'],
			'username' => isset( $site['username'] ) ? (string) $site['username'] : '',
		);
	}
	return $out;
}

/**
 * @param string $id Site id.
 * @return array|null Full site including decrypted password.
 */
function york_sites_get( $id ) {
	$id = sanitize_key( $id );
	if ( ! $id ) {
		return null;
	}
	foreach ( york_sites_all() as $site ) {
		if ( empty( $site['id'] ) || (string) $site['id'] !== $id ) {
			continue;
		}
		$site['app_password'] = york_sites_decrypt( isset( $site['app_password'] ) ? (string) $site['app_password'] : '' );
		return $site;
	}
	return null;
}

/**
 * Cipher prefix for AES-256-GCM payloads.
 */
function york_sites_cipher_prefix() {
	return 'ysk1:';
}

/**
 * 32-byte encryption key derived from WordPress salts (HKDF-SHA256).
 *
 * App passwords must remain recoverable for remote API auth, so they are
 * encrypted (not hashed). MD5/SHA one-way hashes cannot be used here.
 *
 * @return string|false Binary key, or false if crypto is unavailable.
 */
function york_sites_secret_key() {
	if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
		return false;
	}

	$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|york-panel-sites';

	if ( function_exists( 'hash_hkdf' ) ) {
		$key = hash_hkdf( 'sha256', $material, 32, 'york-panel-app-password-v1' );
		return is_string( $key ) && 32 === strlen( $key ) ? $key : false;
	}

	return hash_hmac( 'sha256', 'york-panel-app-password-v1', $material, true );
}

/**
 * Encrypt an Application Password with AES-256-GCM (authenticated encryption).
 *
 * @param string $plain Plain text.
 * @return string|WP_Error Prefixed ciphertext, or error.
 */
function york_sites_encrypt( $plain ) {
	$plain = (string) $plain;
	if ( '' === $plain ) {
		return '';
	}

	$key = york_sites_secret_key();
	if ( ! $key ) {
		return new WP_Error(
			'york_sites_crypto',
			__( 'Secure encryption is unavailable on this server (OpenSSL required).', 'york-panel' )
		);
	}

	$nonce = random_bytes( 12 );
	$tag   = '';
	$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
	if ( false === $cipher || 16 !== strlen( $tag ) ) {
		return new WP_Error( 'york_sites_crypto', __( 'Could not encrypt the Application Password.', 'york-panel' ) );
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- ciphertext packaging only.
	return york_sites_cipher_prefix() . base64_encode( $nonce . $tag . $cipher );
}

/**
 * Decrypt an Application Password (GCM preferred; legacy CBC still supported).
 *
 * @param string $encoded Encoded cipher text.
 * @return string
 */
function york_sites_decrypt( $encoded ) {
	$encoded = (string) $encoded;
	if ( '' === $encoded ) {
		return '';
	}

	$key = york_sites_secret_key();
	if ( ! $key ) {
		return '';
	}

	$prefix = york_sites_cipher_prefix();
	if ( 0 === strpos( $encoded, $prefix ) ) {
		$raw = base64_decode( substr( $encoded, strlen( $prefix ) ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$nonce  = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
		return false === $plain ? '' : $plain;
	}

	// Legacy AES-256-CBC payloads (pre-ysk1).
	$raw = base64_decode( $encoded, true );
	if ( false === $raw || strlen( $raw ) < 17 ) {
		return '';
	}
	$legacy_key = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv         = substr( $raw, 0, 16 );
	$cipher     = substr( $raw, 16 );
	$plain      = openssl_decrypt( $cipher, 'AES-256-CBC', $legacy_key, OPENSSL_RAW_DATA, $iv );
	return false === $plain ? '' : $plain;
}

/**
 * @param string $url URL.
 * @return string|WP_Error
 */
function york_sites_normalize_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return new WP_Error( 'york_sites_url', __( 'Please enter a website URL.', 'york-panel' ) );
	}
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		$url = 'https://' . $url;
	}
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return new WP_Error( 'york_sites_url', __( 'That does not look like a valid URL.', 'york-panel' ) );
	}
	$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
	$host   = strtolower( $parts['host'] );
	$path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
	if ( '/wp-admin' === $path || 0 === strpos( $path, '/wp-admin/' ) ) {
		$path = '';
	}
	return $scheme . '://' . $host . $path;
}

/**
 * @param array $input Input.
 * @return array|WP_Error
 */
function york_sites_save( array $input ) {
	$url = york_sites_normalize_url( isset( $input['url'] ) ? $input['url'] : '' );
	if ( is_wp_error( $url ) ) {
		return $url;
	}
	$username = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';
	$password = isset( $input['app_password'] ) ? preg_replace( '/\s+/', '', (string) $input['app_password'] ) : '';
	$label    = isset( $input['label'] ) ? sanitize_text_field( $input['label'] ) : '';
	$id       = isset( $input['id'] ) ? sanitize_key( $input['id'] ) : '';

	if ( '' === $username || '' === $password ) {
		return new WP_Error( 'york_sites_auth', __( 'Username and Application Password are required.', 'york-panel' ) );
	}
	if ( '' === $label ) {
		$parts = wp_parse_url( $url );
		$label = isset( $parts['host'] ) ? $parts['host'] : $url;
	}
	if ( ! $id ) {
		$id = strtolower( wp_generate_password( 12, false, false ) );
	}

	$encrypted = york_sites_encrypt( $password );
	if ( is_wp_error( $encrypted ) ) {
		return $encrypted;
	}
	if ( '' === $encrypted ) {
		return new WP_Error( 'york_sites_crypto', __( 'Could not encrypt the Application Password.', 'york-panel' ) );
	}

	$sites  = york_sites_all();
	$found  = false;
	$record = array(
		'id'           => $id,
		'label'        => $label,
		'url'          => $url,
		'username'     => $username,
		'app_password' => $encrypted,
		'updated'      => gmdate( 'c' ),
	);

	foreach ( $sites as $i => $site ) {
		if ( isset( $site['id'] ) && (string) $site['id'] === $id ) {
			$sites[ $i ] = $record;
			$found       = true;
			break;
		}
	}
	if ( ! $found ) {
		$sites[] = $record;
	}

	update_option( york_sites_option_key(), $sites, false );
	return $record;
}

/**
 * @param string $id Site id.
 * @return bool
 */
function york_sites_delete( $id ) {
	$id = sanitize_key( $id );
	if ( ! $id ) {
		return false;
	}
	$sites = york_sites_all();
	$next  = array();
	foreach ( $sites as $site ) {
		if ( isset( $site['id'] ) && (string) $site['id'] === $id ) {
			continue;
		}
		$next[] = $site;
	}
	update_option( york_sites_option_key(), $next, false );
	return true;
}

/**
 * Add-website page URL.
 *
 * @return string
 */
function york_sites_add_url() {
	$page = get_page_by_path( 'add-website' );
	if ( $page instanceof WP_Post ) {
		return get_permalink( $page );
	}
	return home_url( '/add-website/' );
}

/**
 * Render site dropdown markup (no secrets).
 *
 * @param array $args Args: name, id, required, selected.
 * @return string
 */
function york_sites_dropdown_html( array $args = array() ) {
	$name     = isset( $args['name'] ) ? $args['name'] : 'site_id';
	$id       = isset( $args['id'] ) ? $args['id'] : 'york-site-id';
	$required = ! empty( $args['required'] );
	$selected = isset( $args['selected'] ) ? (string) $args['selected'] : '';
	$choices  = york_sites_choices();

	ob_start();
	?>
	<div class="york-site-picker">
		<label class="york-site-picker__field" for="<?php echo esc_attr( $id ); ?>">
			<span><?php esc_html_e( 'Website', 'york-panel' ); ?></span>
			<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>" <?php echo $required ? 'required' : ''; ?>>
				<option value=""><?php esc_html_e( 'Select a website…', 'york-panel' ); ?></option>
				<?php foreach ( $choices as $choice ) : ?>
					<option value="<?php echo esc_attr( $choice['id'] ); ?>" <?php selected( $selected, $choice['id'] ); ?>>
						<?php echo esc_html( $choice['label'] . ' — ' . $choice['url'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
		<p class="york-site-picker__add">
			<a href="<?php echo esc_url( york_sites_add_url() ); ?>"><?php esc_html_e( '+ Add website', 'york-panel' ); ?></a>
		</p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Ensure add-website page exists.
 */
function york_sites_ensure_page() {
	$existing = get_page_by_path( 'add-website' );
	if ( $existing instanceof WP_Post ) {
		update_option( 'york_sites_page_id', (int) $existing->ID, false );
		return;
	}
	$page_id = wp_insert_post(
		array(
			'post_title'   => 'Add Website',
			'post_name'    => 'add-website',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[york_add_website]',
			'post_author'  => 1,
		),
		true
	);
	if ( ! is_wp_error( $page_id ) && $page_id ) {
		update_option( 'york_sites_page_id', (int) $page_id, false );
	}
}
add_action( 'init', 'york_sites_ensure_page', 25 );

/**
 * Handle add/delete actions.
 */
function york_sites_handle_post() {
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['york_sites_delete'] ) ) {
		check_admin_referer( 'york_sites_delete' );
		$id = isset( $_POST['site_id'] ) ? sanitize_key( wp_unslash( $_POST['site_id'] ) ) : '';
		york_sites_delete( $id );
		wp_safe_redirect( add_query_arg( 'deleted', '1', york_sites_add_url() ) );
		exit;
	}

	if ( ! isset( $_POST['york_sites_save'] ) ) {
		return;
	}
	check_admin_referer( 'york_sites_save' );

	$result = york_sites_save(
		array(
			'id'           => isset( $_POST['site_id'] ) ? sanitize_key( wp_unslash( $_POST['site_id'] ) ) : '',
			'label'        => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
			'url'          => isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '',
			'username'     => isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '',
			'app_password' => isset( $_POST['app_password'] ) ? (string) wp_unslash( $_POST['app_password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		)
	);

	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( $result->get_error_message() ), york_sites_add_url() ) );
		exit;
	}

	wp_safe_redirect( add_query_arg( 'saved', '1', york_sites_add_url() ) );
	exit;
}
add_action( 'template_redirect', 'york_sites_handle_post', 2 );

/**
 * Shortcode: manage / add websites.
 *
 * @return string
 */
function york_sites_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<p>' . esc_html__( 'Please sign in to manage websites.', 'york-panel' ) . '</p>';
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return '<p>' . esc_html__( 'You do not have permission to manage websites.', 'york-panel' ) . '</p>';
	}

	$sites   = york_sites_choices();
	$saved   = isset( $_GET['saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$deleted = isset( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$error   = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	ob_start();
	?>
	<div class="york-sites-app">
		<header class="york-sites-app__head">
			<p class="yp-eyebrow"><?php esc_html_e( 'Global sites', 'york-panel' ); ?></p>
			<h1><?php esc_html_e( 'Add website', 'york-panel' ); ?></h1>
			<p class="yp-lede"><?php esc_html_e( 'Save WordPress site credentials once, then pick them from the dropdown on Inventory Scan and Website Audit.', 'york-panel' ); ?></p>
		</header>

		<?php if ( $saved ) : ?>
			<div class="york-sites-alert york-sites-alert--ok"><?php esc_html_e( 'Website saved.', 'york-panel' ); ?></div>
		<?php endif; ?>
		<?php if ( $deleted ) : ?>
			<div class="york-sites-alert york-sites-alert--ok"><?php esc_html_e( 'Website removed.', 'york-panel' ); ?></div>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<div class="york-sites-alert york-sites-alert--error"><?php echo esc_html( $error ); ?></div>
		<?php endif; ?>

		<form class="york-sites-form" method="post" action="">
			<?php wp_nonce_field( 'york_sites_save' ); ?>
			<input type="hidden" name="york_sites_save" value="1" />

			<label class="york-sites-field">
				<span><?php esc_html_e( 'Label (optional)', 'york-panel' ); ?></span>
				<input type="text" name="label" placeholder="<?php esc_attr_e( 'FreightWise', 'york-panel' ); ?>" />
			</label>
			<label class="york-sites-field">
				<span><?php esc_html_e( 'Website URL', 'york-panel' ); ?></span>
				<input type="text" name="url" placeholder="https://example.com" required />
			</label>
			<div class="york-sites-form__row">
				<label class="york-sites-field">
					<span><?php esc_html_e( 'WordPress username', 'york-panel' ); ?></span>
					<input type="text" name="username" autocomplete="username" required />
				</label>
				<label class="york-sites-field">
					<span><?php esc_html_e( 'Application Password', 'york-panel' ); ?></span>
					<input type="password" name="app_password" autocomplete="new-password" required />
				</label>
			</div>
			<p class="york-sites-hint">
				<?php esc_html_e( 'On the target site: Users → Profile → Application Passwords.', 'york-panel' ); ?>
			</p>
			<p class="york-sites-hint york-sites-hint--secure">
				<?php esc_html_e( 'Your Application Password is stored with AES-256-GCM authenticated encryption — a modern, secure standard. It is encrypted at rest in this WordPress database and is never shown again after you save.', 'york-panel' ); ?>
			</p>
			<button type="submit" class="yp-btn"><?php esc_html_e( 'Save website', 'york-panel' ); ?></button>
		</form>

		<section class="york-sites-list">
			<h2><?php esc_html_e( 'Saved websites', 'york-panel' ); ?></h2>
			<?php if ( empty( $sites ) ) : ?>
				<p class="york-sites-empty"><?php esc_html_e( 'No websites saved yet.', 'york-panel' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $sites as $site ) : ?>
						<li>
							<div>
								<strong><?php echo esc_html( $site['label'] ); ?></strong>
								<span><?php echo esc_html( $site['url'] ); ?></span>
								<span><?php echo esc_html( $site['username'] ); ?></span>
							</div>
							<form method="post" action="" onsubmit="return confirm('Remove this website?');">
								<?php wp_nonce_field( 'york_sites_delete' ); ?>
								<input type="hidden" name="york_sites_delete" value="1" />
								<input type="hidden" name="site_id" value="<?php echo esc_attr( $site['id'] ); ?>" />
								<button type="submit" class="york-sites-remove"><?php esc_html_e( 'Remove', 'york-panel' ); ?></button>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'york_add_website', 'york_sites_shortcode' );
