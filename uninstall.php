<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package    FFmpeg_Media_Optimizer
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Clear scheduled cron jobs.
wp_clear_scheduled_hook( 'ffmpeg_media_optimizer_cron' );

// 2. Delete plugin settings option.
delete_option( 'ffmpeg_media_optimizer_settings' );

// 3. Drop custom database tables.
$table_images = $wpdb->prefix . 'ffmpeg_media_optimizer_images';
$table_runs   = $wpdb->prefix . 'ffmpeg_media_optimization_runs';

$wpdb->query( "DROP TABLE IF EXISTS {$table_images}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table_runs}" );   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// 4. Remove logs and backups if they exist.
$upload_dir = wp_upload_dir();
if ( ! empty( $upload_dir['basedir'] ) ) {
	$backups_dir = $upload_dir['basedir'] . '/.ffmpeg-media-optimizer-backups';
	if ( is_dir( $backups_dir ) ) {
		// Recursively delete backups directory.
		ffmpeg_media_optimizer_recursive_rmdir( $backups_dir );
	}

	$logs_dir = $upload_dir['basedir'] . '/ffmpeg-media-optimizer-logs';
	if ( is_dir( $logs_dir ) ) {
		ffmpeg_media_optimizer_recursive_rmdir( $logs_dir );
	}
}

/**
 * Recursively deletes a directory and its contents.
 *
 * @param string $dir Directory path.
 */
function ffmpeg_media_optimizer_recursive_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$files = array_diff( scandir( $dir ), array( '.', '..' ) );
	foreach ( $files as $file ) {
		$path = $dir . DIRECTORY_SEPARATOR . $file;
		if ( is_dir( $path ) ) {
			ffmpeg_media_optimizer_recursive_rmdir( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}
