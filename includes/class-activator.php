<?php
/**
 * Plugin activator class.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator
 *
 * Runs checks and configures database and schedules on activation.
 */
class Activator {

	/**
	 * Run the activator actions.
	 */
	public static function activate() {
		// 1. Check PHP version.
		if ( version_compare( PHP_VERSION, '8.1.0', '<' ) ) {
			deactivate_plugins( FFMPEG_MEDIA_OPTIMIZER_BASENAME );
			wp_die(
				esc_html__( 'FFmpeg Media Optimizer requires PHP version 8.1.0 or higher. Activation aborted.', 'ffmpeg-media-optimizer' ),
				esc_html__( 'Activation Error', 'ffmpeg-media-optimizer' ),
				array( 'back_link' => true )
			);
		}

		// 2. Check WordPress version.
		global $wp_version;
		if ( version_compare( $wp_version, '6.5', '<' ) ) {
			deactivate_plugins( FFMPEG_MEDIA_OPTIMIZER_BASENAME );
			wp_die(
				esc_html__( 'FFmpeg Media Optimizer requires WordPress version 6.5 or higher. Activation aborted.', 'ffmpeg-media-optimizer' ),
				esc_html__( 'Activation Error', 'ffmpeg-media-optimizer' ),
				array( 'back_link' => true )
			);
		}

		// 3. Verify proc_open is enabled.
		if ( ! function_exists( 'proc_open' ) ) {
			deactivate_plugins( FFMPEG_MEDIA_OPTIMIZER_BASENAME );
			wp_die(
				esc_html__( 'FFmpeg Media Optimizer requires the PHP function "proc_open" to run shell binaries locally. Please enable it in your php.ini or contact your host.', 'ffmpeg-media-optimizer' ),
				esc_html__( 'Activation Error', 'ffmpeg-media-optimizer' ),
				array( 'back_link' => true )
			);
		}

		// 4. Check if FFmpeg is available.
		$ffmpeg_path = self::locate_ffmpeg();
		if ( ! $ffmpeg_path ) {
			deactivate_plugins( FFMPEG_MEDIA_OPTIMIZER_BASENAME );
			wp_die(
				wp_kses_post(
					__( '<strong>FFmpeg Media Optimizer:</strong> FFmpeg was not found on your server. Please install FFmpeg or ensure it is accessible in your system path, then try activating again.', 'ffmpeg-media-optimizer' )
				),
				esc_html__( 'FFmpeg Not Found', 'ffmpeg-media-optimizer' ),
				array( 'back_link' => true )
			);
		}

		// 5. Create database tables.
		Database::create_tables();

		// 6. Save default settings.
		self::initialize_default_settings( $ffmpeg_path );

		// 7. Flush rewrite rules (if custom WebP/AVIF rewrite rules are needed).
		flush_rewrite_rules();
	}

	/**
	 * Find FFmpeg binary path.
	 *
	 * @return string|bool Path to FFmpeg, or false if not found.
	 */
	public static function locate_ffmpeg() {
		// Check saved settings first.
		$settings = get_option( 'ffmpeg_media_optimizer_settings', array() );
		if ( ! empty( $settings['ffmpeg_path'] ) && self::is_valid_ffmpeg( $settings['ffmpeg_path'] ) ) {
			return $settings['ffmpeg_path'];
		}

		// Common platform binary paths.
		$common_paths = array(
			'/usr/bin/ffmpeg',
			'/usr/local/bin/ffmpeg',
			'/opt/homebrew/bin/ffmpeg',
			'ffmpeg', // System PATH lookup
		);

		// Windows additions.
		if ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
			$common_paths[] = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
			$common_paths[] = 'ffmpeg.exe';
		}

		foreach ( $common_paths as $path ) {
			if ( self::is_valid_ffmpeg( $path ) ) {
				return $path;
			}
		}

		return false;
	}

	/**
	 * Test whether a path points to a valid FFmpeg binary.
	 *
	 * @param string $path Path to FFmpeg.
	 * @return bool True if valid.
	 */
	public static function is_valid_ffmpeg( $path ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		// Run path -version securely.
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// Sanitize commands
		$cmd = escapeshellcmd( $path ) . ' -version';

		$process = @proc_open( $cmd, $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			return false;
		}

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		fclose( $pipes[0] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$return_value = proc_close( $process );

		if ( 0 === $return_value && ( str_contains( $stdout, 'ffmpeg' ) || str_contains( $stderr, 'ffmpeg' ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Setup standard defaults if settings are unconfigured.
	 *
	 * @param string $ffmpeg_path Detected FFmpeg path.
	 */
	private static function initialize_default_settings( $ffmpeg_path ) {
		$defaults = array(
			'enabled'                  => 1,
			'ffmpeg_path'              => $ffmpeg_path,
			'max_concurrent_jobs'      => 2,
			'processing_timeout'       => 60,
			'cpu_priority'             => 'normal',
			'jpeg_quality'             => 82,
			'png_compression'          => 5, // FFmpeg png compression 0-9
			'webp_quality'             => 80,
			'avif_quality'             => 65,
			'min_file_size'            => 1024, // 1KB
			'max_file_size'            => 52428800, // 50MB
			'min_width'                => 100,
			'min_height'               => 100,
			'excluded_mimes'           => array(),
			'optimize_on_upload'       => 1,
			'optimize_regenerated'     => 1,
			'manual_mode'              => 0,
			'night_schedule'           => 0,
			'night_start'              => '22:00',
			'night_end'                => '06:00',
			'enable_backups'           => 1,
			'backup_retention'         => 30, // days
			'enable_cli'               => 1,
			'enable_logging'           => 1,
			'debug_mode'               => 0,
			'conversion_target'        => 'original', // 'original', 'webp', 'avif', 'best'
		);

		$existing = get_option( 'ffmpeg_media_optimizer_settings' );
		if ( false === $existing ) {
			update_option( 'ffmpeg_media_optimizer_settings', $defaults );
		} else {
			// Merge defaults for missing keys
			$merged = array_merge( $defaults, (array) $existing );
			update_option( 'ffmpeg_media_optimizer_settings', $merged );
		}
	}
}
