<?php
/**
 * Category 10: Production Readiness checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_Production {

	/**
	 * @return array[]
	 */
	public static function run() {
		if ( YWA_Helpers::is_remote_audit() ) {
			return YWA_Helpers::skip_category_for_remote(
				'production_readiness',
				__( 'Production readiness content checks use the local database. Skipped for remote site audits.', 'york-website-audit' )
			);
		}

		$labels  = YWA_Categories::get( 'production_readiness' )['checks'];
		$results = array();

		$placeholder_patterns = array(
			'lorem ipsum',
			'placeholder',
			'your title here',
			'sample page',
			'hello world',
			'test content',
			'dummy text',
			'coming soon',
			'asdf',
			'xxx',
			'TODO:',
			'TBD',
		);

		// Pages and posts.
		$content_hits = array();
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 80,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		foreach ( $posts as $post ) {
			$hay = strtolower( $post->post_title . ' ' . wp_strip_all_tags( $post->post_content ) );
			foreach ( $placeholder_patterns as $pattern ) {
				if ( false !== strpos( $hay, $pattern ) ) {
					$content_hits[] = $post->post_type . ' #' . $post->ID . ' — "' . $post->post_title . '" matches "' . $pattern . '"';
					break;
				}
			}
			if ( in_array( $post->post_name, array( 'hello-world', 'sample-page' ), true ) ) {
				$content_hits[] = $post->post_type . ' #' . $post->ID . ' — default slug "' . $post->post_name . '"';
			}
		}
		$results[] = YWA_Helpers::result(
			'pages_posts',
			$labels['pages_posts'],
			empty( $content_hits ) ? 'pass' : ( count( $content_hits ) <= 2 ? 'warn' : 'fail' ),
			sprintf( 'Scanned %d pages/posts; %d placeholder/demo matches.', count( $posts ), count( $content_hits ) ),
			array_slice( $content_hits, 0, 10 ),
			1.4
		);

		// Media library placeholders.
		$media_hits = array();
		$media = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( $media as $item ) {
			$name = strtolower( $item->post_title . ' ' . basename( get_attached_file( $item->ID ) ) );
			if ( preg_match( '/(placeholder|dummy|sample|lorem|test-|demo-|default)/', $name ) ) {
				$media_hits[] = '#' . $item->ID . ' — ' . $item->post_title;
			}
		}
		$results[] = YWA_Helpers::result(
			'media_library',
			$labels['media_library'],
			empty( $media_hits ) ? 'pass' : ( count( $media_hits ) <= 2 ? 'warn' : 'fail' ),
			sprintf( 'Reviewed %d recent media items; %d look like placeholders.', count( $media ), count( $media_hits ) ),
			array_slice( $media_hits, 0, 8 ),
			1.1
		);

		// Forms — Gravity Forms / CF7 / WPForms test entries or titles.
		$form_hits = array();
		if ( class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'get_forms' ) ) {
			$forms = GFAPI::get_forms( true );
			foreach ( (array) $forms as $form ) {
				$title = isset( $form['title'] ) ? $form['title'] : '';
				if ( preg_match( '/test|demo|sample|lorem/i', $title ) ) {
					$form_hits[] = 'Gravity Form: ' . $title;
				}
			}
		}
		$cf7 = get_posts(
			array(
				'post_type'      => 'wpcf7_contact_form',
				'posts_per_page' => 20,
				'post_status'    => 'any',
			)
		);
		foreach ( $cf7 as $form ) {
			if ( preg_match( '/test|demo|sample/i', $form->post_title ) ) {
				$form_hits[] = 'CF7: ' . $form->post_title;
			}
		}
		$has_forms = YWA_Helpers::has_active_plugin( array( 'contact-form-7', 'gravityforms', 'wpforms', 'formidable', 'ninja-forms' ) );
		$results[] = YWA_Helpers::result(
			'forms',
			$labels['forms'],
			! empty( $form_hits ) ? 'fail' : ( $has_forms ? 'pass' : 'pass' ),
			! empty( $form_hits )
				? 'Test/demo forms found.'
				: ( $has_forms ? 'Form plugin present; no obvious test form titles.' : 'No common form plugin active.' ),
			$form_hits,
			1.1
		);

		// Test accounts.
		$test_users = array();
		$user_query = new WP_User_Query(
			array(
				'number' => 50,
				'fields' => array( 'ID', 'user_login', 'user_email' ),
			)
		);
		foreach ( $user_query->get_results() as $user ) {
			$blob = strtolower( $user->user_login . ' ' . $user->user_email );
			if ( preg_match( '/\b(test|demo|dummy|asdf|temp|qa\+|staging)\b/', $blob )
				|| preg_match( '/@(example\.com|test\.com|mailinator\.com)/', $blob ) ) {
				$test_users[] = $user->user_login . ' <' . $user->user_email . '>';
			}
		}
		$results[] = YWA_Helpers::result(
			'test_accounts',
			$labels['test_accounts'],
			empty( $test_users ) ? 'pass' : ( count( $test_users ) <= 1 ? 'warn' : 'fail' ),
			sprintf( 'Found %d possible test account(s).', count( $test_users ) ),
			array_slice( $test_users, 0, 8 ),
			1.3
		);

		// Placeholder content overall + debug/environment.
		$env_issues = array();
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$env_issues[] = 'WP_DEBUG is enabled';
		}
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && in_array( WP_ENVIRONMENT_TYPE, array( 'development', 'local', 'staging' ), true ) ) {
			$env_issues[] = 'WP_ENVIRONMENT_TYPE is ' . WP_ENVIRONMENT_TYPE;
		}
		$html = YWA_Helpers::homepage_html();
		foreach ( array( 'lorem ipsum', 'placeholder', 'your site title', 'just another wordpress site' ) as $needle ) {
			if ( false !== stripos( $html, $needle ) ) {
				$env_issues[] = 'Homepage contains: ' . $needle;
			}
		}
		$blogname = strtolower( (string) get_bloginfo( 'name' ) );
		if ( in_array( $blogname, array( '', 'my site', 'wordpress', 'just another wordpress site' ), true )
			|| false !== strpos( $blogname, 'test' ) ) {
			$env_issues[] = 'Site title looks unfinished: ' . get_bloginfo( 'name' );
		}

		$placeholder_count = count( $content_hits ) + count( $media_hits ) + count( $form_hits ) + count( $env_issues );
		$results[] = YWA_Helpers::result(
			'placeholder_content',
			$labels['placeholder_content'],
			0 === $placeholder_count ? 'pass' : ( $placeholder_count <= 3 ? 'warn' : 'fail' ),
			sprintf( 'Aggregate placeholder/demo signals: %d.', $placeholder_count ),
			array_slice( $env_issues, 0, 8 ),
			1.4
		);

		return $results;
	}
}
