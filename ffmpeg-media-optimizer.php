<?php
/**
 * Plugin Name:       FFmpeg Media Optimizer
 * Plugin URI:        https://wordpress.org/plugins/ffmpeg-media-optimizer/
 * Description:       A self-hosted image optimization plugin that optimizes WordPress media library images locally using FFmpeg.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            WordPress Plugin Engineer
 * License:           GPLv2 or later
 * License URI:      https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ffmpeg-media-optimizer
 * Domain Path:       /languages
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Plugin Constants.
define( 'FFMPEG_MEDIA_OPTIMIZER_VERSION', '1.0.0' );
define( 'FFMPEG_MEDIA_OPTIMIZER_PATH', plugin_dir_path( __FILE__ ) );
define( 'FFMPEG_MEDIA_OPTIMIZER_URL', plugin_dir_url( __FILE__ ) );
define( 'FFMPEG_MEDIA_OPTIMIZER_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for FFmpeg Media Optimizer classes.
 * Maps namespace FfmpegMediaOptimizer to files following WP CS class naming conventions:
 * - FfmpegMediaOptimizer\Plugin -> includes/class-plugin.php
 * - FfmpegMediaOptimizer\Admin\Settings -> admin/class-settings.php
 */
spl_autoload_register( function ( $class ) {
	$prefix = 'FfmpegMediaOptimizer\\';
	$len    = strlen( $prefix );

	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );

	// Split by namespace separator
	$parts     = explode( '\\', $relative_class );
	$class_name = array_pop( $parts );

	// Form filename: class-name.php (WP CS style, e.g. class-background-processor.php)
	$filename = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

	// Directory mapping
	$sub_dir = '';
	if ( ! empty( $parts ) ) {
		$sub_dir = strtolower( implode( DIRECTORY_SEPARATOR, $parts ) ) . DIRECTORY_SEPARATOR;
	}

	// Determine if it is in admin, public, or includes
	if ( str_starts_with( $sub_dir, 'admin' . DIRECTORY_SEPARATOR ) ) {
		$file = FFMPEG_MEDIA_OPTIMIZER_PATH . $sub_dir . $filename;
	} elseif ( str_starts_with( $sub_dir, 'public' . DIRECTORY_SEPARATOR ) || 'Public_Handler' === $class_name ) {
		$file = FFMPEG_MEDIA_OPTIMIZER_PATH . 'public' . DIRECTORY_SEPARATOR . $filename;
	} else {
		$file = FFMPEG_MEDIA_OPTIMIZER_PATH . 'includes' . DIRECTORY_SEPARATOR . $sub_dir . $filename;
	}

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Activation Hook.
 */
function activate_ffmpeg_media_optimizer() {
	// Let Activator class handle the activation process.
	\FfmpegMediaOptimizer\Activator::activate();
}
register_activation_hook( __FILE__, 'activate_ffmpeg_media_optimizer' );

/**
 * Deactivation Hook.
 */
function deactivate_ffmpeg_media_optimizer() {
	// Let Deactivator class handle the deactivation process.
	\FfmpegMediaOptimizer\Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'deactivate_ffmpeg_media_optimizer' );

/**
 * Initialize the plugin.
 */
function run_ffmpeg_media_optimizer() {
	$plugin = new \FfmpegMediaOptimizer\Plugin();
	$plugin->run();
}
run_ffmpeg_media_optimizer();
