<?php
/**
 * Audit categories sourced from York IE Audit Questions (Website Team v2).
 * Only Question, How to Check, and Possible Answer (1–5) are used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Categories {

	/**
	 * @return array<string, array>
	 */
	public static function all() {
		return array(
			'code_quality'         => array(
				'id'       => 'code_quality',
				'number'   => 1,
				'title'    => 'Code Quality & Documentation',
				'question' => 'What is the quality, maintainability, and documentation standard of custom code (themes, plugins, customizations)?',
				'checks'   => array(
					'review_comments_docs'   => 'Review code comments and documentation',
					'coding_standards'       => 'Check code against WordPress Coding Standards',
					'deprecated_functions'   => 'Look for deprecated functions',
					'security_issues'        => 'Scan for security issues (SQL injection, XSS)',
					'escaping_sanitization'  => 'Verify proper data escaping and sanitization',
					'slow_queries'           => 'Check for slow queries (N+1 problems)',
					'open_random_files'      => 'Review theme PHP, CSS, and JS files',
					'header_comments'        => 'Verify header comments and documentation standards',
				),
				'answers'  => array(
					1 => 'Custom code contains security vulnerabilities, deprecated functions, poor documentation, and no coding standards.',
					2 => 'Some good code but inconsistent standards, missing sanitization, and weak documentation.',
					3 => 'Generally good code following standards but missing documentation or some best practices.',
					4 => 'High-quality code following standards, well-documented, secure, and performant.',
					5 => 'Exemplary code with automated linting, comprehensive documentation, security-first approach, and optimized performance.',
				),
			),
			'performance'          => array(
				'id'       => 'performance',
				'number'   => 2,
				'title'    => 'Performance Optimization',
				'question' => 'How well is the website optimized for performance, assets, images, accessibility, and Core Web Vitals?',
				'checks'   => array(
					'lighthouse'          => 'Run Lighthouse metrics',
					'core_web_vitals'     => 'Check Core Web Vitals',
					'caching'             => 'Verify caching implementation',
					'image_formats'       => 'Review image formats (WebP)',
					'image_optimization'  => 'Verify image optimization',
					'css_js_assets'       => 'Inspect CSS and JS assets',
					'minification'        => 'Verify minification and compression',
					'unused_assets'       => 'Check unused CSS and JS',
					'accessibility'       => 'Verify accessibility standards',
					'agentic_browsing'    => 'Agentic Browsing',
					'image_alt_text'      => 'Verify image alt text',
				),
				'answers'  => array(
					1 => 'Site performance is poor with major optimization issues affecting user experience.',
					2 => 'Some optimization exists but significant performance bottlenecks remain.',
					3 => 'Performance meets basic expectations but opportunities exist.',
					4 => 'Website is fast, optimized, and provides a good user experience.',
					5 => 'Highly optimized website with excellent Core Web Vitals and accessibility compliance.',
				),
			),
			'security'             => array(
				'id'       => 'security',
				'number'   => 3,
				'title'    => 'Security & Monitoring',
				'question' => 'How effectively are website security, sensitive data protection, and activity monitoring implemented?',
				'checks'   => array(
					'authentication'      => 'Review authentication controls',
					'rbac'                => 'Verify role-based access permissions',
					'security_plugins'    => 'Review security plugins and tools',
					'encryption'          => 'Check data encryption',
					'secrets'             => 'Review secrets management',
					'audit_logs'          => 'Verify audit logs',
					'activity_monitoring' => 'Review activity monitoring',
					'login_security'      => 'Verify login security controls',
					'vulnerabilities'     => 'Review vulnerability reports',
				),
				'answers'  => array(
					1 => 'Critical security vulnerabilities with little monitoring or protection.',
					2 => 'Basic security controls exist but several risks remain.',
					3 => 'Reasonable security posture with basic monitoring.',
					4 => 'Strong security controls and monitoring practices.',
					5 => 'Security-first implementation with proactive monitoring and governance.',
				),
			),
			'plugin_governance'    => array(
				'id'       => 'plugin_governance',
				'number'   => 4,
				'title'    => 'Plugin & Platform Governance',
				'question' => 'How effectively are plugins, tools, services, and third-party dependencies managed, documented, and maintained?',
				'checks'   => array(
					'installed_plugins' => 'Review installed plugins',
					'plugin_updates'    => 'Check plugin update status',
					'unused_plugins'    => 'Review unused plugins',
					'licensing'         => 'Review licensing status',
					'plugin_docs'       => 'Verify plugin documentation',
					'third_party'       => 'Review third-party services',
					'required_plugins'  => 'Review required plugins e.g WP Activity Log, AIOS',
				),
				'answers'  => array(
					1 => 'Plugins are outdated, unmanaged, and introduce security or maintenance risks.',
					2 => 'Basic management exists but governance is inconsistent.',
					3 => 'Plugins are reasonably managed.',
					4 => 'Strong plugin governance and maintenance process.',
					5 => 'Well-governed ecosystem with documented ownership and lifecycle management.',
				),
			),
			'seo_analytics'        => array(
				'id'       => 'seo_analytics',
				'number'   => 5,
				'title'    => 'SEO, Analytics & Redirect Management',
				'question' => 'How effectively is the website configured for SEO, analytics tracking, indexing, and URL migration readiness?',
				'checks'   => array(
					'metadata'          => 'Review metadata',
					'search_console'    => 'Review Search Console',
					'sitemap'           => 'Verify sitemap configuration',
					'analytics'         => 'Validate analytics tracking',
					'redirects'         => 'Review redirect management',
					'event_tracking'    => 'Verify event tracking',
					'robots_txt'        => 'Review robots.txt',
					'broken_links'      => 'Review Broken Links',
					'aeo_geo'           => 'Review AEO/GEO',
					'canonical'         => 'Review canonical tags',
				),
				'answers'  => array(
					1 => 'Critical SEO and tracking issues.',
					2 => 'Basic implementation with significant gaps.',
					3 => 'Core SEO and analytics capabilities implemented.',
					4 => 'Strong discoverability and analytics coverage.',
					5 => 'Fully optimized and measurable.',
				),
			),
			'technical_docs'       => array(
				'id'       => 'technical_docs',
				'number'   => 6,
				'title'    => 'Technical Documentation',
				'question' => 'How comprehensive is the technical documentation for the website for maintenance guides and API documentation (where WordPress is used headless with any frontend framework)?',
				'checks'   => array(
					'maintenance_guides' => 'Review maintenance guides',
					'api_documentation'  => 'Review API documentation (if WordPress is headless with Next.js/other frontend)',
				),
				'answers'  => array(
					1 => 'Documentation is largely missing.',
					2 => 'Documentation exists but is incomplete.',
					3 => 'Most critical documentation exists.',
					4 => 'Documentation is comprehensive and maintained.',
					5 => 'Documentation is complete, current, and easy to use.',
				),
			),
			'css_typography'       => array(
				'id'       => 'css_typography',
				'number'   => 7,
				'title'    => 'CSS Architecture & Typography System',
				'question' => 'Is the site using a modern CSS architecture with SCSS, responsive design principles, and a consistent typography system?',
				'checks'   => array(
					'scss_structure'     => 'Review SCSS structure',
					'naming_conventions' => 'Review naming conventions',
					'responsive'         => 'Verify responsive implementation',
					'rem_sizing'         => 'Verify REM-based sizing',
					'typography'         => 'Review typography hierarchy',
					'consistency'        => 'Review consistency across pages',
				),
				'answers'  => array(
					1 => 'Inconsistent styling with poor maintainability.',
					2 => 'Some standards exist but inconsistently applied.',
					3 => 'Reasonably organized styling system.',
					4 => 'Well-structured CSS architecture.',
					5 => 'Highly maintainable design system with strong consistency.',
				),
			),
			'cms_admin'            => array(
				'id'       => 'cms_admin',
				'number'   => 8,
				'title'    => 'CMS Admin Experience',
				'question' => 'Is the backend admin panel intuitive, with clearly labelled fields and a content structure that non-technical editors can use independently?',
				'checks'   => array(
					'editor_workflows'  => 'Review editor workflows',
					'field_naming'      => 'Review field naming',
					'content_structure' => 'Review content structure',
					'permissions'       => 'Verify permissions',
					'publishing'        => 'Observe content publishing process',
				),
				'answers'  => array(
					1 => 'Editors depend heavily on developers.',
					2 => 'Editors face regular usability challenges.',
					3 => 'Most content can be managed independently.',
					4 => 'Efficient and intuitive editing experience.',
					5 => 'Highly intuitive and fully self-service.',
				),
			),
			'backup_recoverability'=> array(
				'id'       => 'backup_recoverability',
				'number'   => 9,
				'title'    => 'Backup & Recoverability',
				'question' => 'Has a full local backup of the project (files and database) been taken and securely stored as a safety copy independent of the live server?',
				'checks'   => array(
					'backup_files'     => 'Verify backup files',
					'database_backup'  => 'Verify database backup',
					'storage_location' => 'Verify storage location',
					'restore_process'  => 'Verify restore process',
					'backup_schedule'  => 'Review backup schedule',
				),
				'answers'  => array(
					1 => 'No reliable backup exists.',
					2 => 'Backups exist but are incomplete or unverified.',
					3 => 'Backups exist and can be restored.',
					4 => 'Reliable backup and recovery process exists.',
					5 => 'Automated and tested backup strategy with documented recovery process.',
				),
			),
			'production_readiness' => array(
				'id'       => 'production_readiness',
				'number'   => 10,
				'title'    => 'Production Readiness',
				'question' => 'Has all placeholder, demo, and test content been completely removed from the production site?',
				'checks'   => array(
					'pages_posts'         => 'Review pages and posts',
					'media_library'       => 'Review media library',
					'forms'               => 'Review forms',
					'test_accounts'       => 'Review test accounts',
					'placeholder_content' => 'Review placeholder content',
				),
				'answers'  => array(
					1 => 'Significant demo and test content exists in production.',
					2 => 'Several placeholder elements remain.',
					3 => 'Minor cleanup items remain.',
					4 => 'Production environment is clean.',
					5 => 'Production environment is fully validated with release governance.',
				),
			),
		);
	}

	/**
	 * @param string $id Category id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}
}
