<?php
/**
 * Category 8: CMS Admin Experience checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_CMS {

	/**
	 * @return array[]
	 */
	public static function run() {
		if ( YWA_Helpers::is_remote_audit() ) {
			$labels = YWA_Categories::get( 'cms_admin' )['checks'];
			$has_acf = YWA_Helpers::has_active_plugin( array( 'advanced-custom-fields', 'acf-' ) );
			$has_editor_enh = YWA_Helpers::has_active_plugin( array( 'classic-editor', 'block-editor', 'genesis-blocks', 'kadence-blocks', 'generateblocks', 'carbon-fields' ) );
			$results = array();
			$results[] = YWA_Helpers::result(
				'editor_workflows',
				$labels['editor_workflows'],
				( $has_acf || $has_editor_enh ) ? 'pass' : 'warn',
				( $has_acf || $has_editor_enh )
					? 'Structured editing tooling detected on the selected site (ACF/editor enhancements).'
					: 'Limited structured editing tooling detected on the selected site.',
				array( 'ACF: ' . ( $has_acf ? 'yes' : 'no' ) ),
				1.2
			);
			foreach ( array( 'field_naming', 'content_structure', 'permissions', 'publishing' ) as $id ) {
				if ( empty( $labels[ $id ] ) ) {
					continue;
				}
				$results[] = YWA_Helpers::result(
					$id,
					$labels[ $id ],
					'skip',
					__( 'This CMS check needs the target site’s admin APIs/filesystem beyond plugins list. Skipped for remote audits.', 'york-website-audit' ),
					array(),
					1.0
				);
			}
			return $results;
		}

		$labels  = YWA_Categories::get( 'cms_admin' )['checks'];
		$results = array();

		$has_acf      = YWA_Helpers::has_active_plugin( array( 'advanced-custom-fields', 'acf-' ) ) || function_exists( 'acf_get_field_groups' );
		$has_blocks   = YWA_Helpers::has_active_plugin( array( 'safe-svg' ) ) || file_exists( YWA_Helpers::theme_dir() . '/theme.json' );
		$has_editor_enh = YWA_Helpers::has_active_plugin( array( 'classic-editor', 'block-editor', 'genesis-blocks', 'kadence-blocks', 'generateblocks', 'carbon-fields' ) );

		// Editor workflows.
		$results[] = YWA_Helpers::result(
			'editor_workflows',
			$labels['editor_workflows'],
			( $has_acf || $has_blocks || $has_editor_enh ) ? 'pass' : 'warn',
			( $has_acf || $has_blocks || $has_editor_enh )
				? 'Structured editing tooling detected (ACF/blocks/editor enhancements).'
				: 'Limited structured editing tooling detected — editors may need developer help.',
			array(
				'ACF: ' . ( $has_acf ? 'yes' : 'no' ),
				'theme.json / blocks signal: ' . ( $has_blocks ? 'yes' : 'no' ),
			),
			1.2
		);

		// Field naming via ACF groups when available.
		$field_evidence = array();
		$field_status   = 'warn';
		if ( function_exists( 'acf_get_field_groups' ) ) {
			$groups = acf_get_field_groups();
			$good   = 0;
			$total  = 0;
			foreach ( (array) $groups as $group ) {
				$title = isset( $group['title'] ) ? $group['title'] : '';
				if ( '' === $title ) {
					continue;
				}
				++$total;
				if ( ! preg_match( '/^(field|group|test|tmp)_/i', $title ) && strlen( $title ) > 2 ) {
					++$good;
					$field_evidence[] = $title;
				}
			}
			if ( $total > 0 ) {
				$field_status = ( $good / $total ) >= 0.7 ? 'pass' : 'warn';
			}
			$results[] = YWA_Helpers::result(
				'field_naming',
				$labels['field_naming'],
				$field_status,
				sprintf( 'Reviewed %d ACF field group title(s); %d look human-readable.', $total, $good ),
				array_slice( $field_evidence, 0, 8 ),
				1.2
			);
		} else {
			$results[] = YWA_Helpers::result(
				'field_naming',
				$labels['field_naming'],
				'warn',
				'ACF not available to inspect field labels. Review custom fields manually in wp-admin.',
				array(),
				1.0
			);
		}

		// Content structure — CPTs / taxonomies.
		$cpts = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);
		$taxonomies = get_taxonomies(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'names'
		);
		$cpt_names = array();
		foreach ( $cpts as $cpt ) {
			$cpt_names[] = $cpt->label . ' (' . $cpt->name . ')';
		}
		$results[] = YWA_Helpers::result(
			'content_structure',
			$labels['content_structure'],
			( count( $cpts ) > 0 || count( $taxonomies ) > 0 ) ? 'pass' : 'warn',
			sprintf( 'Custom post types: %d · Custom taxonomies: %d.', count( $cpts ), count( $taxonomies ) ),
			array_slice( $cpt_names, 0, 10 ),
			1.1
		);

		// Permissions — editors shouldn't manage options/plugins.
		$editor = get_role( 'editor' );
		$perm_issues = array();
		if ( $editor ) {
			foreach ( array( 'install_plugins', 'activate_plugins', 'manage_options', 'edit_themes' ) as $cap ) {
				if ( $editor->has_cap( $cap ) ) {
					$perm_issues[] = 'Editor has capability: ' . $cap;
				}
			}
		} else {
			$perm_issues[] = 'Editor role missing';
		}
		$results[] = YWA_Helpers::result(
			'permissions',
			$labels['permissions'],
			empty( $perm_issues ) ? 'pass' : 'fail',
			empty( $perm_issues ) ? 'Editor role permissions look appropriate for non-technical users.' : 'Editor role has elevated capabilities.',
			$perm_issues,
			1.3
		);

		// Publishing process — revisions, autosave defaults.
		$revisions = defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : true;
		$results[] = YWA_Helpers::result(
			'publishing',
			$labels['publishing'],
			$revisions ? 'pass' : 'warn',
			$revisions ? 'Post revisions enabled — supports safe publishing workflows.' : 'Post revisions disabled — publishing safety reduced.',
			array( 'WP_POST_REVISIONS: ' . ( is_bool( $revisions ) ? ( $revisions ? 'true' : 'false' ) : (string) $revisions ) ),
			1.0
		);

		return $results;
	}
}
