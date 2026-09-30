<?php
/**
 * Category 9: Backup & Recoverability checks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YWA_Check_Backup {

	/**
	 * @return array[]
	 */
	public static function run() {
		$labels  = YWA_Categories::get( 'backup_recoverability' )['checks'];
		$results = array();

		if ( YWA_Helpers::is_remote_audit() ) {
			$backup_plugins = array(
				'updraftplus',
				'backupbuddy',
				'backwpup',
				'duplicator',
				'ai1wm',
				'all-in-one-wp-migration',
				'vaultpress',
				'jetpack',
				'wpvivid',
				'blogvault',
				'snapshot',
				'xcloner',
				'boldgrid-backup',
			);
			$has_backup_plugin = YWA_Helpers::has_active_plugin( $backup_plugins );
			$offsite_signal    = YWA_Helpers::has_active_plugin( array( 'updraftplus', 'vaultpress', 'blogvault', 'jetpack', 'wpvivid' ) );

			$results[] = YWA_Helpers::result(
				'backup_files',
				$labels['backup_files'],
				$has_backup_plugin ? 'pass' : 'fail',
				$has_backup_plugin ? 'Backup plugin detected on the selected site.' : 'No common backup plugin detected on the selected site.',
				array(),
				1.4
			);
			$results[] = YWA_Helpers::result(
				'database_backup',
				$labels['database_backup'],
				$has_backup_plugin ? 'pass' : 'fail',
				$has_backup_plugin ? 'Backup plugin on selected site typically includes database backups.' : 'No backup plugin detected for database backups on the selected site.',
				array(),
				1.4
			);
			$results[] = YWA_Helpers::result(
				'storage_location',
				$labels['storage_location'],
				$offsite_signal ? 'pass' : ( $has_backup_plugin ? 'warn' : 'fail' ),
				$offsite_signal
					? 'Backup plugin with common offsite support detected on selected site. Confirm remote destinations manually.'
					: 'Could not confirm independent offsite backup storage on the selected site.',
				array(),
				1.3
			);
			$results[] = YWA_Helpers::result(
				'restore_process',
				$labels['restore_process'],
				'skip',
				__( 'Restore documentation cannot be read on remote sites.', 'york-website-audit' ),
				array(),
				1.0
			);
			$results[] = YWA_Helpers::result(
				'backup_schedule',
				$labels['backup_schedule'],
				$has_backup_plugin ? 'warn' : 'fail',
				$has_backup_plugin
					? 'Backup plugin present on selected site — confirm schedule in that site’s wp-admin.'
					: 'No backup plugin detected on the selected site.',
				array(),
				1.1
			);
			return $results;
		}

		$backup_plugins = array(
			'updraftplus',
			'backupbuddy',
			'backwpup',
			'duplicator',
			'ai1wm',
			'all-in-one-wp-migration',
			'vaultpress',
			'jetpack',
			'wpvivid',
			'blogvault',
			'snapshot',
			'xcloner',
			'boldgrid-backup',
		);
		$has_backup_plugin = YWA_Helpers::has_active_plugin( $backup_plugins );

		// Look for backup artifacts.
		$search_dirs = array(
			WP_CONTENT_DIR . '/updraft',
			WP_CONTENT_DIR . '/ai1wm-backups',
			WP_CONTENT_DIR . '/backups',
			WP_CONTENT_DIR . '/backup',
			WP_CONTENT_DIR . '/uploads/backup',
			WP_CONTENT_DIR . '/uploads/backups',
			WP_CONTENT_DIR . '/uploads/wpvivid',
			ABSPATH . 'backups',
		);
		$file_backups = array();
		$db_backups   = array();
		foreach ( $search_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = YWA_Helpers::find_files( $dir, array( 'zip', 'gz', 'sql', 'tar', 'wpress', 'bak' ), 30 );
			foreach ( $files as $file ) {
				$rel = YWA_Helpers::rel_path( $file );
				$file_backups[] = $rel;
				if ( preg_match( '/\.(sql|sql\.gz|sql\.zip)$/i', $file ) || false !== stripos( $file, 'db' ) ) {
					$db_backups[] = $rel;
				}
			}
		}

		$results[] = YWA_Helpers::result(
			'backup_files',
			$labels['backup_files'],
			( $has_backup_plugin || ! empty( $file_backups ) ) ? 'pass' : 'fail',
			$has_backup_plugin || ! empty( $file_backups )
				? 'Backup plugin and/or backup files detected.'
				: 'No backup plugin or backup files found on disk.',
			array_slice( $file_backups, 0, 8 ),
			1.4
		);

		$results[] = YWA_Helpers::result(
			'database_backup',
			$labels['database_backup'],
			( $has_backup_plugin || ! empty( $db_backups ) ) ? 'pass' : 'fail',
			( $has_backup_plugin || ! empty( $db_backups ) )
				? 'Database backup capability or SQL artifacts detected.'
				: 'No database backup artifacts found.',
			array_slice( $db_backups, 0, 8 ),
			1.4
		);

		// Storage location independence.
		$offsite_signal = YWA_Helpers::has_active_plugin( array( 'updraftplus', 'vaultpress', 'blogvault', 'jetpack', 'wpvivid' ) );
		$local_only     = ! empty( $file_backups ) && ! $offsite_signal;
		$results[] = YWA_Helpers::result(
			'storage_location',
			$labels['storage_location'],
			$offsite_signal ? 'pass' : ( ! empty( $file_backups ) ? 'warn' : 'fail' ),
			$offsite_signal
				? 'Backup plugin with common offsite support detected. Confirm remote destinations manually.'
				: ( $local_only
					? 'Only local backup files found — ensure an independent offsite copy exists.'
					: 'No clear independent backup storage detected.' ),
			array(),
			1.3
		);

		// Restore process documentation.
		$restore_docs = array();
		foreach ( array(
			YWA_Helpers::theme_dir() . '/docs/restore.md',
			YWA_Helpers::theme_dir() . '/RESTORE.md',
			ABSPATH . 'docs/restore.md',
			ABSPATH . 'RESTORE.md',
			WP_CONTENT_DIR . '/RESTORE.md',
		) as $path ) {
			if ( file_exists( $path ) ) {
				$restore_docs[] = YWA_Helpers::rel_path( $path );
			}
		}
		$results[] = YWA_Helpers::result(
			'restore_process',
			$labels['restore_process'],
			! empty( $restore_docs ) ? 'pass' : ( $has_backup_plugin ? 'warn' : 'fail' ),
			! empty( $restore_docs )
				? 'Restore documentation found.'
				: ( $has_backup_plugin
					? 'Backup plugin present but no documented restore process found in repo.'
					: 'No restore process documentation or backup tooling found.' ),
			$restore_docs,
			1.2
		);

		// Backup schedule — infer from plugin options when possible.
		$schedule_ev = array();
		$scheduled   = false;
		$updraft = get_option( 'updraft_interval' );
		if ( ! empty( $updraft ) ) {
			$scheduled     = true;
			$schedule_ev[] = 'UpdraftPlus interval: ' . ( is_string( $updraft ) ? $updraft : wp_json_encode( $updraft ) );
		}
		$crons = _get_cron_array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( array_keys( (array) $hooks ) as $hook ) {
					if ( preg_match( '/backup|updraft|backwpup|wpvivid|duplicator/i', $hook ) ) {
						$scheduled     = true;
						$schedule_ev[] = 'Cron hook: ' . $hook;
					}
				}
			}
		}
		$results[] = YWA_Helpers::result(
			'backup_schedule',
			$labels['backup_schedule'],
			$scheduled ? 'pass' : ( $has_backup_plugin ? 'warn' : 'fail' ),
			$scheduled ? 'Scheduled backup signals found.' : 'No scheduled backup cron/options detected.',
			array_slice( array_unique( $schedule_ev ), 0, 8 ),
			1.2
		);

		return $results;
	}
}
