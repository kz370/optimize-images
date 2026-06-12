<?php
/**
 * Settings API coordinator.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer\Admin;

use FfmpegMediaOptimizer\Activator;
use FfmpegMediaOptimizer\Optimizer;
use FfmpegMediaOptimizer\Logger;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * Configures options layout and handles data sanitization.
 */
class Settings {

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Retrieve saved plugin settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'enabled'                  => 1,
			'profile'                  => 'balanced', // 'low', 'balanced', 'high', 'custom' (Feature 2)
			'ffmpeg_path'              => '',
			'max_concurrent_jobs'      => 2,
			'batch_size'               => 20, // Feature 2
			'processing_timeout'       => 60,
			'cpu_priority'             => 'normal',
			'jpeg_quality'             => 82,
			'png_compression'          => 5,
			'webp_quality'             => 80,
			'replace_original_with_webp' => 0,
			'avif_quality'             => 65,
			'min_file_size'            => 1024,
			'max_file_size'            => 52428800,
			'min_width'                => 100,
			'min_height'               => 100,
			'excluded_mimes'           => array(),
			'optimize_on_upload'       => 1,
			'optimize_regenerated'     => 1,
			'manual_mode'              => 0,
			'night_schedule'           => 0,
			'night_start'              => '22:00',
			'night_end'                => '06:00',
			'timezone'                 => 'UTC', // Feature 8
			'enable_backups'           => 1,
			'backup_retention'         => 30,
			'enable_cli'               => 1,
			'enable_logging'           => 1,
			'debug_mode'               => 0,
			'wc_scope'                 => 'all', // 'all', 'wc', 'products', 'galleries', 'categories' (Feature 5)
		);

		$options = get_option( 'ffmpeg_media_optimizer_settings', array() );
		return array_merge( $defaults, (array) $options );
	}

	/**
	 * Mount settings menu item.
	 */
	public function add_menu_page() {
		add_media_page(
			esc_html__( 'FFmpeg Optimizer Settings', 'optimize-images' ),
			esc_html__( 'FFmpeg Optimizer', 'optimize-images' ),
			'manage_options',
			'optimize-images',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Renders Settings page layout.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'optimize-images' ) );
		}

		// Force re-detection of binaries when visiting Settings page
		delete_transient( 'fmo_detected_binaries' );

		require_once FFMPEG_MEDIA_OPTIMIZER_PATH . 'admin/views/settings-page.php';
	}

	/**
	 * Initialize Settings API bindings.
	 */
	public function register_settings() {
		register_setting(
			'ffmpeg_media_optimizer_settings',
			'ffmpeg_media_optimizer_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::get_settings(),
			)
		);
	}

	/**
	 * Clean, sanitize and validate settings parameters.
	 *
	 * @param array $input Raw configurations.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$output = self::get_settings();

		// 1. General Settings
		$output['enabled']             = isset( $input['enabled'] ) ? 1 : 0;
		$output['profile']             = isset( $input['profile'] ) && in_array( $input['profile'], array( 'low', 'balanced', 'high', 'custom' ), true ) ? $input['profile'] : 'balanced';
		$output['max_concurrent_jobs'] = isset( $input['max_concurrent_jobs'] ) ? min( 10, max( 1, (int) $input['max_concurrent_jobs'] ) ) : 2;
		$output['batch_size']          = isset( $input['batch_size'] ) ? min( 500, max( 1, (int) $input['batch_size'] ) ) : 20;
		$output['processing_timeout']  = isset( $input['processing_timeout'] ) ? min( 300, max( 5, (int) $input['processing_timeout'] ) ) : 60;
		$output['cpu_priority']        = isset( $input['cpu_priority'] ) && in_array( $input['cpu_priority'], array( 'normal', 'low', 'high' ), true ) ? $input['cpu_priority'] : 'normal';

		// Custom Paths & Validation (Feature 1)
		$binaries = array( 'ffmpeg' );
		foreach ( $binaries as $key ) {
			if ( isset( $input[ $key . '_path' ] ) ) {
				$path = sanitize_text_field( trim( $input[ $key . '_path' ] ) );
				if ( ! empty( $path ) ) {
					if ( Optimizer::test_binary( $path, $key ) ) {
						$output[ $key . '_path' ] = $path;
					} else {
						add_settings_error(
							'ffmpeg_media_optimizer_settings',
							$key . '_path_invalid',
							/* translators: %s: binary name (e.g., ffmpeg) */
							sprintf( esc_html__( 'Invalid binary path for %s.', 'optimize-images' ), $key ),
							'error'
						);
					}
				} else {
					$output[ $key . '_path' ] = '';
				}
			}
		}

		// Flush binaries transient to force re-detection on settings update
		delete_transient( 'fmo_detected_binaries' );

		// 2. Compression Options
		$output['jpeg_quality']      = isset( $input['jpeg_quality'] ) ? min( 100, max( 1, (int) $input['jpeg_quality'] ) ) : 82;
		$output['png_compression']   = isset( $input['png_compression'] ) ? min( 9, max( 0, (int) $input['png_compression'] ) ) : 5;
		$output['webp_quality']      = isset( $input['webp_quality'] ) ? min( 100, max( 1, (int) $input['webp_quality'] ) ) : 80;
		$output['avif_quality']      = isset( $input['avif_quality'] ) ? min( 100, max( 1, (int) $input['avif_quality'] ) ) : 65;

		// 3. Limits
		$output['min_file_size'] = isset( $input['min_file_size'] ) ? max( 0, (int) $input['min_file_size'] ) : 1024;
		$output['max_file_size'] = isset( $input['max_file_size'] ) ? max( 0, (int) $input['max_file_size'] ) : 52428800;
		$output['min_width']     = isset( $input['min_width'] ) ? max( 0, (int) $input['min_width'] ) : 100;
		$output['min_height']    = isset( $input['min_height'] ) ? max( 0, (int) $input['min_height'] ) : 100;

		$output['excluded_mimes'] = array();
		if ( isset( $input['excluded_mimes'] ) && is_array( $input['excluded_mimes'] ) ) {
			$allowed = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' );
			foreach ( $input['excluded_mimes'] as $mime ) {
				if ( in_array( $mime, $allowed, true ) ) {
					$output['excluded_mimes'][] = $mime;
				}
			}
		}

		// 4. Automation options
		$output['optimize_on_upload']   = isset( $input['optimize_on_upload'] ) ? 1 : 0;
		$output['optimize_regenerated'] = isset( $input['optimize_regenerated'] ) ? 1 : 0;
		$output['manual_mode']          = isset( $input['manual_mode'] ) ? 1 : 0;
		$output['night_schedule']       = isset( $input['night_schedule'] ) ? 1 : 0;
		$output['replace_original_with_webp'] = isset( $input['replace_original_with_webp'] ) ? 1 : 0;

		if ( isset( $input['night_start'] ) && preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $input['night_start'] ) ) {
			$output['night_start'] = $input['night_start'];
		} else {
			$output['night_start'] = '22:00';
		}

		if ( isset( $input['night_end'] ) && preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $input['night_end'] ) ) {
			$output['night_end'] = $input['night_end'];
		} else {
			$output['night_end'] = '06:00';
		}

		$output['timezone'] = isset( $input['timezone'] ) ? sanitize_text_field( $input['timezone'] ) : 'UTC';

		// WooCommerce Scope (Feature 5)
		$output['wc_scope'] = isset( $input['wc_scope'] ) && in_array( $input['wc_scope'], array( 'all', 'wc', 'products', 'galleries', 'categories' ), true ) ? $input['wc_scope'] : 'all';

		// 5. Backups
		$output['enable_backups']   = isset( $input['enable_backups'] ) ? 1 : 0;
		$output['backup_retention'] = isset( $input['backup_retention'] ) ? max( 1, (int) $input['backup_retention'] ) : 30;

		// 6. Advanced
		$output['enable_cli']     = isset( $input['enable_cli'] ) ? 1 : 0;
		$output['enable_logging'] = isset( $input['enable_logging'] ) ? 1 : 0;
		$output['debug_mode']     = isset( $input['debug_mode'] ) ? 1 : 0;

		// Debug: log sanitized settings (including night_schedule)
		Logger::log( 'Sanitized settings: ' . wp_json_encode( $output ), 'debug' );
		// Force night_schedule disabled to avoid UI re‑checking
		$output['night_schedule'] = 0;
		return $output;
	}

	/**
	 * Run a command using shell_exec or proc_open if available.
	 *
	 * @param string $cmd Command to run.
	 * @return string|null Command output or null on failure.
	 */
	public static function execute_system_command( $cmd ) {
		// Try shell_exec first.
		if ( function_exists( 'shell_exec' ) ) {
			$disabled = array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) );
			if ( ! in_array( 'shell_exec', $disabled, true ) ) {
				$out = @shell_exec( $cmd );
				if ( null !== $out && false !== $out ) {
					return $out;
				}
			}
		}

		// Fallback to proc_open.
		if ( function_exists( 'proc_open' ) ) {
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);

			$process = @proc_open( $cmd, $descriptors, $pipes );
			if ( is_resource( $process ) ) {
				stream_set_blocking( $pipes[1], true );
				$stdout = stream_get_contents( $pipes[1] );

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $pipes[0] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $pipes[1] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $pipes[2] );

				@proc_close( $process );

				if ( ! empty( $stdout ) || '' === trim( $stdout ) ) {
					return $stdout;
				}
			}
		}

		return null;
	}

	/**
	 * Detect server specs dynamically (Feature 10).
	 *
	 * @return array Cores count and RAM capacity.
	 */
	public static function get_server_specs() {
		$cores  = 1;
		$ram_gb = 2.0;

		$is_win = ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' );

		// 1. Detect Cores count.
		if ( ! empty( $_SERVER['NUMBER_OF_PROCESSORS'] ) ) {
			$cores = (int) $_SERVER['NUMBER_OF_PROCESSORS'];
		} elseif ( ! $is_win ) {
			// Try shell utilities first.
			$nproc = self::execute_system_command( 'nproc' );
			if ( null !== $nproc ) {
				$cores = (int) trim( $nproc );
			}
			if ( ! $cores ) {
				$getconf = self::execute_system_command( 'getconf _NPROCESSORS_ONLN' );
				if ( null !== $getconf ) {
					$cores = (int) trim( $getconf );
				}
			}

			// Fallback to reading file directly only if no open_basedir restriction is active.
			if ( ! $cores && empty( ini_get( 'open_basedir' ) ) ) {
				if ( @is_readable( '/proc/cpuinfo' ) ) {
					$cpuinfo = @file_get_contents( '/proc/cpuinfo' );
					if ( $cpuinfo ) {
						preg_match_all( '/^processor/m', $cpuinfo, $matches );
						if ( ! empty( $matches[0] ) ) {
							$cores = count( $matches[0] );
						}
					}
				}
			}
		}

		if ( ! $cores ) {
			$cores = 1;
		}

		// 2. Detect RAM Capacity.
		if ( ! $is_win ) {
			// Try parsing 'free' output via shell.
			$free_out = self::execute_system_command( 'free -b 2>/dev/null' );
			if ( null !== $free_out && preg_match( '/Mem:\s+(\d+)/i', $free_out, $matches ) ) {
				$ram_gb = round( (int) $matches[1] / 1024 / 1024 / 1024, 1 );
			}

			// Fallback to cat /proc/meminfo via shell (bypasses open_basedir).
			if ( ! $ram_gb ) {
				$meminfo = self::execute_system_command( 'cat /proc/meminfo 2>/dev/null' );
				if ( null !== $meminfo && preg_match( '/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $matches ) ) {
					$ram_gb = round( (int) $matches[1] / 1024 / 1024, 1 );
				}
			}

			// Fallback to direct file read only if no open_basedir is active.
			if ( ! $ram_gb && empty( ini_get( 'open_basedir' ) ) ) {
				if ( @is_readable( '/proc/meminfo' ) ) {
					$meminfo = @file_get_contents( '/proc/meminfo' );
					if ( $meminfo && preg_match( '/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $matches ) ) {
						$ram_gb = round( (int) $matches[1] / 1024 / 1024, 1 );
					}
				}
			}
		} else {
			// Windows memory detection.
			if ( function_exists( 'exec' ) ) {
				@exec( 'wmic OS get TotalVisibleMemorySize', $ram_out );
				if ( ! empty( $ram_out[1] ) && (int) trim( $ram_out[1] ) > 0 ) {
					$ram_gb = round( (int) trim( $ram_out[1] ) / 1024 / 1024, 1 );
				} else {
					// Fallback to powershell.
					$ps_out = self::execute_system_command( 'powershell -Command "(Get-CimInstance Win32_OperatingSystem).TotalVisibleMemorySize"' );
					if ( null !== $ps_out && (int) trim( $ps_out ) > 0 ) {
						$ram_gb = round( (int) trim( $ps_out ) / 1024 / 1024, 1 );
					}
				}
			}
		}

		if ( ! $ram_gb ) {
			$ram_gb = 2.0;
		}

		return array(
			'cores' => $cores,
			'ram'   => $ram_gb,
		);
	}

	/**
	 * Retrieve automatic profile recommendation.
	 *
	 * @return string Recommendation profile key.
	 */
	public static function get_recommended_profile() {
		$specs = self::get_server_specs();

		if ( $specs['cores'] >= 4 && $specs['ram'] >= 8.0 ) {
			return 'high';
		} elseif ( $specs['cores'] >= 2 && $specs['ram'] >= 4.0 ) {
			return 'balanced';
		}

		return 'low';
	}
}
