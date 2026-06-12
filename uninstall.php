<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package    Optimize_Images
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Uninstall routine for cleaning up database and files.
 */
function optimize_images_uninstall() {
	global $wpdb;

	// 1. Clear scheduled cron jobs.
	wp_clear_scheduled_hook( 'ffmpeg_media_optimizer_cron' );

	// 2. Delete plugin settings option.
	delete_option( 'ffmpeg_media_optimizer_settings' );

	// 3. Drop custom database tables.
	$table_images = $wpdb->prefix . 'ffmpeg_media_optimizer_images';
	$table_runs   = $wpdb->prefix . 'ffmpeg_media_optimization_runs';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_images ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_runs ) );

	// 4. Remove logs and backups if they exist.
	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['basedir'] ) ) {
		$backups_dir = $upload_dir['basedir'] . '/.optimize-images-backups';
		$logs_dir    = $upload_dir['basedir'] . '/optimize-images-logs';

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			if ( $wp_filesystem->is_dir( $backups_dir ) ) {
				$wp_filesystem->rmdir( $backups_dir, true );
			}
			if ( $wp_filesystem->is_dir( $logs_dir ) ) {
				$wp_filesystem->rmdir( $logs_dir, true );
			}
		}
	}
}
optimize_images_uninstall();
