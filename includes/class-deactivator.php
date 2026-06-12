<?php
/**
 * Plugin deactivator class.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deactivator
 *
 * Runs cleanup tasks on deactivation.
 */
class Deactivator {

	/**
	 * Run the deactivator actions.
	 */
	public static function deactivate() {
		// Clear scheduled cron jobs.
		wp_clear_scheduled_hook( 'ffmpeg_media_optimizer_cron' );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}
}
