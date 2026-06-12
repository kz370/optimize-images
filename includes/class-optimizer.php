<?php
/**
 * Core image optimizer engine.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Optimizer
 *
 * Handles execution of FFmpeg commands, local binary pipelines, duplicate checks, backup, and logging.
 */
class Optimizer {

	/**
	 * Run the optimization process on an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Result status.
	 */
	public function optimize_attachment( $attachment_id ) {
		$start_time_float = microtime( true );
		$start_time       = current_time( 'mysql', 1 );
		$settings         = Admin\Settings::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Optimization is disabled in settings.', 'ffmpeg-media-optimizer' ),
			);
		}

		$file_path = get_attached_file( $attachment_id );
		$original_size = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;
		$filename = $file_path ? basename( $file_path ) : 'ID ' . $attachment_id;

		// Initial state update so user sees file details
		$state = Background_Processor::get_queue_state();
		$state['current_file']      = $filename;
		$state['current_orig_size'] = size_format( $original_size );
		$state['current_opt_size']  = __( 'Optimizing...', 'ffmpeg-media-optimizer' );
		Background_Processor::save_queue_state( $state );

		// Validate paths and file existence
		$error_msg = '';
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			$error_msg = __( 'Attachment file does not exist.', 'ffmpeg-media-optimizer' );
		} elseif ( ! $this->is_path_in_uploads( $file_path ) ) {
			$error_msg = __( 'Security violation: File is outside the uploads directory.', 'ffmpeg-media-optimizer' );
		} elseif ( ! $this->passes_limits( $file_path, $original_size ) ) {
			$error_msg = __( 'File does not meet limit requirements (size/dimensions).', 'ffmpeg-media-optimizer' );
		}

		if ( ! empty( $error_msg ) ) {
			$existing_records = Database::get_image_by_attachment( $attachment_id );
			$opt_count        = $existing_records ? (int) $existing_records->optimization_count + 1 : 1;
			Database::save_image_status(
				array(
					'attachment_id'      => $attachment_id,
					'file_path'          => $file_path ? $file_path : '',
					'original_size'      => $original_size,
					'current_size'       => $original_size,
					'optimization_count' => $opt_count,
					'status'             => 'failed',
					'hash'               => '',
					'last_optimized_at'  => $start_time,
				)
			);

			Database::log_run(
				array(
					'attachment_id'  => $attachment_id,
					'optimizer_used' => 'none',
					'format_used'    => $file_path ? strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) : 'unknown',
					'quality_used'   => 0,
					'saved_bytes'    => 0,
					'saved_percent'  => 0,
					'status'         => 'failed',
					'error_message'  => $error_msg,
					'duration'       => 0,
					'started_at'     => $start_time,
					'finished_at'    => $start_time,
				)
			);

			Logger::log( sprintf( 'Failed/Skipped attachment ID %d: %s', $attachment_id, $error_msg ), 'error' );

			$state = Background_Processor::get_queue_state();
			$state['current_opt_size'] = __( 'Failed', 'ffmpeg-media-optimizer' );
			Background_Processor::save_queue_state( $state );

			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$hash = md5_file( $file_path );

		// Check for duplicate images already optimized in database (Feature 9)
		$duplicate = $this->find_optimized_duplicate( $hash, $attachment_id );
		if ( $duplicate ) {
			$duration = round( microtime( true ) - $start_time_float, 2 );
			$res = $this->reuse_duplicate_optimization( $attachment_id, $file_path, $original_size, $hash, $duplicate, $duration );
			if ( $res['success'] ) {
				$state = Background_Processor::get_queue_state();
				$state['current_opt_size'] = size_format( filesize( $file_path ) ) . ' (' . __( 'duplicate', 'ffmpeg-media-optimizer' ) . ')';
				Background_Processor::save_queue_state( $state );
				return $res;
			} else {
				Logger::log( sprintf( 'Duplicate reuse failed for attachment ID %d: %s. Falling back to normal optimization.', $attachment_id, $res['error'] ), 'warning' );
			}
		}


		// Detect available binaries (Feature 1)
		$binaries = self::detect_binaries();
		$start_time = current_time( 'mysql', 1 );

		// List of all file sizes to optimize (original + thumbnails)
		$files_to_optimize = array(
			'original' => $file_path,
		);

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$dirname  = dirname( $file_path );

		if ( ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $size_info ) {
				if ( ! empty( $size_info['file'] ) ) {
					$thumb_path = $dirname . DIRECTORY_SEPARATOR . $size_info['file'];
					if ( file_exists( $thumb_path ) && $this->is_path_in_uploads( $thumb_path ) ) {
						$files_to_optimize[ $size ] = $thumb_path;
					}
				}
			}
		}

		$total_saved_bytes = 0;
		$errors            = array();
		$optimizer_used    = 'none';
		$format_used       = 'original';
		$quality_used      = 0;
		$backups_enabled   = ! empty( $settings['enable_backups'] );

		foreach ( $files_to_optimize as $size_key => $target_file ) {
			$mime_type = wp_check_filetype( $target_file )['type'];
			if ( empty( $mime_type ) || $this->is_mime_excluded( $mime_type ) ) {
				continue;
			}

			// Get sizes of target
			$target_orig_size = filesize( $target_file );

			// Backup if enabled
			if ( $backups_enabled ) {
				$this->backup_file( $target_file );
			}

			// Route optimization through local engines (Feature 1)
			$temp_file = $target_file . '.optimized.' . pathinfo( $target_file, PATHINFO_EXTENSION );
			$result    = $this->run_engine_optimization( $binaries, $target_file, $temp_file, $mime_type, $settings );

			if ( $result['success'] && file_exists( $temp_file ) ) {
				$temp_size = filesize( $temp_file );

				if ( $temp_size > 0 && $temp_size < $target_orig_size ) {
					// Use copy + unlink instead of unlink + rename to avoid partial failure 404s on Windows
					if ( @copy( $temp_file, $target_file ) ) {
						unlink( $temp_file );
						$total_saved_bytes += ( $target_orig_size - $temp_size );

						if ( 'original' === $size_key ) {
							$optimizer_used = $result['optimizer'];
							$format_used    = 'webp';
							$quality_used   = isset( $settings['webp_quality'] ) ? (int) $settings['webp_quality'] : 80;
						}
					} else {
						unlink( $temp_file );
						$errors[] = sprintf( '%s: Failed to copy optimized file over original.', $size_key );
					}
				} else {
					unlink( $temp_file );
				}
			} else {
				if ( file_exists( $temp_file ) ) {
					unlink( $temp_file );
				}
				$errors[] = sprintf( '%s: %s', $size_key, $result['error'] );
			}

			// All optimized images are WebP, no extra formats generation needed
		}

		$finished_time    = current_time( 'mysql', 1 );
		$current_size     = filesize( $file_path );
		$existing_records = Database::get_image_by_attachment( $attachment_id );
		$opt_count        = $existing_records ? (int) $existing_records->optimization_count + 1 : 1;
		$duration         = (int) round( microtime( true ) - $start_time_float );

		if ( empty( $errors ) || $total_saved_bytes > 0 ) {
			// Update status in Database
			Database::save_image_status(
				array(
					'attachment_id'      => $attachment_id,
					'file_path'          => $file_path,
					'original_size'      => $original_size,
					'current_size'       => $current_size,
					'optimization_count' => $opt_count,
					'status'             => 'optimized',
					'hash'               => $hash,
					'last_optimized_at'  => $finished_time,
				)
			);

			// Log run
			Database::log_run(
				array(
					'attachment_id'  => $attachment_id,
					'optimizer_used' => $optimizer_used,
					'format_used'    => $format_used,
					'quality_used'   => $quality_used,
					'saved_bytes'    => $total_saved_bytes,
					'saved_percent'  => $original_size > 0 ? round( ( $total_saved_bytes / $original_size ) * 100, 2 ) : 0,
					'status'         => 'success',
					'duration'       => $duration,
					'started_at'     => $start_time,
					'finished_at'    => $finished_time,
				)
			);

			Logger::log( sprintf( 'Successfully optimized attachment ID %d using %s. Saved %d bytes in %ds.', $attachment_id, $optimizer_used, $total_saved_bytes, $duration ), 'info' );

			$state = Background_Processor::get_queue_state();
			$state['current_opt_size'] = size_format( $current_size );
			Background_Processor::save_queue_state( $state );

			return array(
				'success' => true,
				'saved'   => $total_saved_bytes,
			);
		} else {
			// Failed run
			Database::save_image_status(
				array(
					'attachment_id'      => $attachment_id,
					'file_path'          => $file_path,
					'original_size'      => $original_size,
					'current_size'       => $current_size,
					'optimization_count' => $opt_count,
					'status'             => 'failed',
					'hash'               => $hash,
				)
			);

			$error_message = implode( '; ', $errors );
			Database::log_run(
				array(
					'attachment_id'  => $attachment_id,
					'optimizer_used' => $optimizer_used !== 'none' ? $optimizer_used : 'unknown',
					'format_used'    => $format_used,
					'quality_used'   => 0,
					'saved_bytes'    => 0,
					'saved_percent'  => 0,
					'status'         => 'failed',
					'error_message'  => $error_message,
					'duration'       => $duration,
					'started_at'     => $start_time,
					'finished_at'    => $finished_time,
				)
			);

			Logger::log( sprintf( 'Failed to optimize attachment ID %d. Errors: %s', $attachment_id, $error_message ), 'error' );

			$state = Background_Processor::get_queue_state();
			$state['current_opt_size'] = __( 'Failed', 'ffmpeg-media-optimizer' );
			Background_Processor::save_queue_state( $state );

			return array(
				'success' => false,
				'error'   => $error_message,
			);
		}
	}

	/**
	 * Detect available binaries.
	 *
	 * @return array Map of binaries configurations.
	 */
	public static function detect_binaries() {
		$cache = get_transient( 'fmo_detected_binaries' );
		if ( false !== $cache ) {
			return $cache;
		}

		$settings = Admin\Settings::get_settings();
		$binaries = array(
			'ffmpeg'    => 'ffmpeg',
		);

		$detected = array();

		foreach ( $binaries as $key => $default_name ) {
			$custom_path = isset( $settings[ $key . '_path' ] ) ? trim( $settings[ $key . '_path' ] ) : '';
			if ( ! empty( $custom_path ) && self::test_binary( $custom_path, $key ) ) {
				$detected[ $key ] = array(
					'path'    => $custom_path,
					'version' => self::get_binary_version( $custom_path, $key ),
				);
				continue;
			}

			// Path search list
			$paths    = array();
			$resolved = self::resolve_binary_path( $default_name );
			if ( ! empty( $resolved ) ) {
				$paths[] = $resolved;
			}
			$paths[] = $default_name;

			if ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' ) {
				$paths[] = 'C:\\ProgramData\\chocolatey\\bin\\' . $default_name . '.exe';
				$paths[] = 'C:\\ProgramData\\chocolatey\\lib\\' . $default_name . '\\tools\\' . $default_name . '.exe';
				$paths[] = 'C:\\tools\\' . $default_name . '\\' . $default_name . '.exe';
				$paths[] = 'C:\\tools\\' . $default_name . '.exe';
				$paths[] = 'C:\\Program Files\\' . $default_name . '\\' . $default_name . '.exe';
				$paths[] = 'C:\\Program Files (x86)\\' . $default_name . '\\' . $default_name . '.exe';
				$paths[] = 'C:\\ffmpeg\\bin\\' . $default_name . '.exe';
				$paths[] = 'C:\\laragon\\bin\\image-optimizers\\' . $default_name . '.exe';
				$paths[] = 'C:\\laragon\\bin\\ffmpeg\\bin\\' . $default_name . '.exe';
				$paths[] = $default_name . '.exe';
			} else {
				$paths[] = '/usr/bin/' . $default_name;
				$paths[] = '/usr/local/bin/' . $default_name;
				$paths[] = '/opt/homebrew/bin/' . $default_name;
			}

			$found = false;
			foreach ( $paths as $path ) {
				if ( self::test_binary( $path, $key ) ) {
					$detected[ $key ] = array(
						'path'    => $path,
						'version' => self::get_binary_version( $path, $key ),
					);
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$detected[ $key ] = false;
			}
		}

		set_transient( 'fmo_detected_binaries', $detected, 12 * HOUR_IN_SECONDS );
		return $detected;
	}

	/**
	 * Test whether a path runs a binary correctly.
	 */
	public static function test_binary( $path, $type ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$flag = ' -version';

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$cmd     = escapeshellcmd( $path ) . $flag;
		$process = @proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			// Try with no flag if process failed (some binaries don't support version flag)
			$cmd     = escapeshellcmd( $path );
			$process = @proc_open( $cmd, $descriptors, $pipes );
			if ( ! is_resource( $process ) ) {
				return false;
			}
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$code = proc_close( $process );
		$output = strtolower( $stdout . $stderr );

		// Check for common shell command error messages
		if ( str_contains( $output, 'not recognized' ) || 
			 str_contains( $output, 'not found' ) || 
			 str_contains( $output, 'no such file' ) ||
			 str_contains( $output, 'cannot find' ) ||
			 str_contains( $output, 'permission denied' ) ) {
			return false;
		}

		if ( 'ffmpeg' === $type && str_contains( $output, 'ffmpeg' ) ) {
			return true;
		}

		// Success exit code check
		if ( 0 === $code ) {
			return true;
		}

		// Fallback check: only if a physical executable file exists at the path
		if ( file_exists( $path ) && is_executable( $path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Parse binary output to fetch version.
	 */
	public static function get_binary_version( $path, $type ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return 'unknown';
		}

		$flag = ' -version';

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$cmd     = escapeshellcmd( $path ) . $flag;
		$process = @proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return 'unknown';
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		$output = $stdout . $stderr;

		if ( 'ffmpeg' === $type ) {
			if ( preg_match( '/ffmpeg version ([\d\.\w\-]+)/i', $output, $m ) ) {
				return $m[1];
			}
		}

		return 'detected';
	}

	private function run_engine_optimization( $binaries, $input, $output, $mime, $settings ) {
		if ( ! empty( $binaries['ffmpeg'] ) ) {
			$res = $this->run_ffmpeg_optimization( $binaries['ffmpeg']['path'], $input, $output, $mime, $settings );
			$res['optimizer'] = 'ffmpeg';
			return $res;
		}

		return array(
			'success' => false,
			'error'   => __( 'FFmpeg binary is not available.', 'ffmpeg-media-optimizer' ),
		);
	}

	/**
	 * Run FFmpeg command execution.
	 */
	private function run_ffmpeg_optimization( $binary, $input, $output, $mime, $settings ) {
		$timeout  = isset( $settings['processing_timeout'] ) ? (int) $settings['processing_timeout'] : 60;
		$priority = isset( $settings['cpu_priority'] ) ? $settings['cpu_priority'] : 'normal';

		$quality = isset( $settings['webp_quality'] ) ? (int) $settings['webp_quality'] : 80;
		$args    = array(
			'-y',
			'-i', $input,
			'-codec:v', 'libwebp',
			'-lossless', '0',
			'-quality', (string) $quality,
			'-f', 'webp',
			$output
		);

		return $this->execute_command( $binary, $args, $timeout, $priority );
	}

	/**
	 * Execute command via proc_open safely.
	 */
	private function execute_command( $binary, $args, $timeout, $priority ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'proc_open function is disabled on this server.', 'ffmpeg-media-optimizer' ),
			);
		}

		$escaped_args = array_map( 'escapeshellarg', $args );
		$base_cmd     = escapeshellcmd( $binary ) . ' ' . implode( ' ', $escaped_args );
		$cmd          = $base_cmd;
		$using_nice   = false;

		if ( 'low' === $priority ) {
			if ( strtoupper( substr( PHP_OS, 0, 3 ) ) !== 'WIN' ) {
				$cmd        = 'nice -n 19 ' . $base_cmd;
				$using_nice = true;
			}
		}

		Logger::log( 'Executing: ' . $cmd, 'debug' );
		$res = $this->run_process( $cmd, $timeout );

		// If nice failed (e.g. exit code 234, permission/cgroups constraint on Linux Docker), retry without nice.
		if ( ! $res['success'] && $using_nice ) {
			Logger::log( sprintf( 'Command failed with nice (exit code %d). Retrying direct execution without nice...', $res['exit_code'] ), 'warning' );
			Logger::log( 'Executing (fallback): ' . $base_cmd, 'debug' );
			$res = $this->run_process( $base_cmd, $timeout );
		}

		if ( ! $res['success'] ) {
			$error_msg = $res['error'];
			if ( ! empty( $res['stderr'] ) ) {
				$error_msg .= ' Stderr: ' . trim( $res['stderr'] );
			}
			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		return array(
			'success' => true,
		);
	}

	/**
	 * Run command process execution helper.
	 */
	private function run_process( $cmd, $timeout ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = @proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return array(
				'success'   => false,
				'exit_code' => -1,
				'error'     => __( 'Could not execute process.', 'ffmpeg-media-optimizer' ),
			);
		}

		$start_time = time();
		$terminated = false;

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout = '';
		$stderr = '';

		$exit_code = -1;
		while ( true ) {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$exit_code = $status['exitcode'];
				break;
			}

			if ( ( time() - $start_time ) > $timeout ) {
				proc_terminate( $process );
				$terminated = true;
				break;
			}

			$stdout .= fread( $pipes[1], 8192 );
			$stderr .= fread( $pipes[2], 8192 );

			usleep( 20000 );
		}

		$stdout .= stream_get_contents( $pipes[1] );
		$stderr .= stream_get_contents( $pipes[2] );

		fclose( $pipes[0] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$close_code = proc_close( $process );
		if ( $exit_code === -1 && $close_code !== -1 ) {
			$exit_code = $close_code;
		}

		if ( $terminated ) {
			return array(
				'success'   => false,
				'exit_code' => -2,
				'error'     => __( 'Command execution timed out.', 'ffmpeg-media-optimizer' ),
			);
		}

		if ( 0 !== $exit_code ) {
			return array(
				'success'   => false,
				'exit_code' => $exit_code,
				'error'     => sprintf( __( 'Process failed with exit code %d.', 'ffmpeg-media-optimizer' ), $exit_code ),
				'stdout'    => $stdout,
				'stderr'    => $stderr,
			);
		}

		return array(
			'success'   => true,
			'exit_code' => 0,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
		);
	}

	/**
	 * Optionally generate WebP/AVIF versions of the image.
	 */
	private function generate_modern_formats( $binary, $file_path, $mime, $settings ) {
		$target = isset( $settings['conversion_target'] ) ? $settings['conversion_target'] : 'original';
		if ( 'original' === $target ) {
			return;
		}

		$timeout      = isset( $settings['processing_timeout'] ) ? (int) $settings['processing_timeout'] : 60;
		$priority     = isset( $settings['cpu_priority'] ) ? $settings['cpu_priority'] : 'normal';
		$file_without_ext = pathinfo( $file_path, PATHINFO_DIRNAME ) . DIRECTORY_SEPARATOR . pathinfo( $file_path, PATHINFO_FILENAME );

		if ( 'webp' === $target || 'best' === $target ) {
			$webp_file = $file_without_ext . '.webp';
			if ( ! file_exists( $webp_file ) ) {
				$quality = isset( $settings['webp_quality'] ) ? (int) $settings['webp_quality'] : 80;
				$args    = array( '-y', '-i', $file_path, '-codec:v', 'libwebp', '-quality', (string) $quality, $webp_file );
				$this->execute_command( $binary, $args, $timeout, $priority );
			}
		}

		if ( 'avif' === $target || 'best' === $target ) {
			$avif_file = $file_without_ext . '.avif';
			if ( ! file_exists( $avif_file ) ) {
				$quality = isset( $settings['avif_quality'] ) ? (int) $settings['avif_quality'] : 65;
				$crf     = 15 + round( ( 100 - $quality ) * ( 48 / 100 ) );
				$args    = array( '-y', '-i', $file_path, '-codec:v', 'libaom-av1', '-crf', (string) $crf, '-strict', 'experimental', $avif_file );
				$this->execute_command( $binary, $args, $timeout, $priority );
			}
		}
	}

	/**
	 * Verify that a path lies inside the uploads directory.
	 */
	public function is_path_in_uploads( $path ) {
		$upload_dir = wp_upload_dir();
		$basedir    = realpath( $upload_dir['basedir'] );
		if ( ! $basedir ) {
			return false;
		}

		$dir = realpath( dirname( $path ) );
		if ( ! $dir ) {
			return false;
		}

		return str_starts_with( $dir, $basedir );
	}

	/**
	 * Backup the given file.
	 */
	private function backup_file( $file_path ) {
		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		$relative = str_replace( $basedir, '', $file_path );
		$backup   = $basedir . '/.ffmpeg-media-optimizer-backups' . $relative;

		if ( file_exists( $backup ) ) {
			return;
		}

		wp_mkdir_p( dirname( $backup ) );
		copy( $file_path, $backup );
	}

	/**
	 * Check if file sizes or dimensions meet processing limits.
	 */
	private function passes_limits( $file_path, $size ) {
		$settings = Admin\Settings::get_settings();

		if ( ! empty( $settings['min_file_size'] ) && $size < (int) $settings['min_file_size'] ) {
			return false;
		}
		if ( ! empty( $settings['max_file_size'] ) && $size > (int) $settings['max_file_size'] ) {
			return false;
		}

		$dims = @getimagesize( $file_path );
		if ( ! $dims ) {
			return false;
		}

		$width  = $dims[0];
		$height = $dims[1];

		if ( ! empty( $settings['min_width'] ) && $width < (int) $settings['min_width'] ) {
			return false;
		}
		if ( ! empty( $settings['min_height'] ) && $height < (int) $settings['min_height'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Verify if a mime type is excluded.
	 */
	private function is_mime_excluded( $mime ) {
		$settings = Admin\Settings::get_settings();
		$excluded = isset( $settings['excluded_mimes'] ) ? (array) $settings['excluded_mimes'] : array();
		return in_array( $mime, $excluded, true );
	}

	/**
	 * Retrieve quality value from setting based on mime.
	 */
	private function get_quality_for_mime( $mime, $settings ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return isset( $settings['jpeg_quality'] ) ? (int) $settings['jpeg_quality'] : 82;
			case 'image/webp':
				return isset( $settings['webp_quality'] ) ? (int) $settings['webp_quality'] : 80;
			case 'image/avif':
				return isset( $settings['avif_quality'] ) ? (int) $settings['avif_quality'] : 65;
			default:
				return 0;
		}
	}

	/**
	 * Search database for an optimized image with same hash (Feature 9).
	 */
	private function find_optimized_duplicate( $hash, $attachment_id ) {
		global $wpdb;
		$table = Database::get_images_table();
		$query = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE hash = %s AND attachment_id != %d AND status = 'optimized' LIMIT 1",
			$hash,
			$attachment_id
		);
		return $wpdb->get_row( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Reuses optimization results from a duplicate attachment.
	 */
	private function reuse_duplicate_optimization( $attachment_id, $file_path, $original_size, $hash, $duplicate, $duration ) {
		$success = false;

		// Verify duplicate file exists
		if ( file_exists( $duplicate->file_path ) ) {
			// Copy original
			if ( copy( $duplicate->file_path, $file_path ) ) {
				$success = true;
			}

			// Copy thumbnails if duplicate meta exists
			$metadata = wp_get_attachment_metadata( $attachment_id );
			$dup_meta = wp_get_attachment_metadata( $duplicate->attachment_id );
			$dirname  = dirname( $file_path );
			$dup_dir  = dirname( $duplicate->file_path );

			if ( ! empty( $metadata['sizes'] ) && ! empty( $dup_meta['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size => $size_info ) {
					if ( ! empty( $size_info['file'] ) && ! empty( $dup_meta['sizes'][ $size ]['file'] ) ) {
						$target_thumb = $dirname . DIRECTORY_SEPARATOR . $size_info['file'];
						$source_thumb = $dup_dir . DIRECTORY_SEPARATOR . $dup_meta['sizes'][ $size ]['file'];
						if ( file_exists( $source_thumb ) && $this->is_path_in_uploads( $target_thumb ) ) {
							copy( $source_thumb, $target_thumb );
						}
					}
				}
			}
		}

		if ( $success ) {
			$now          = current_time( 'mysql', 1 );
			$current_size = filesize( $file_path );
			$saved_bytes  = max( 0, $original_size - $current_size );

			Database::save_image_status(
				array(
					'attachment_id'      => $attachment_id,
					'file_path'          => $file_path,
					'original_size'      => $original_size,
					'current_size'       => $current_size,
					'optimization_count' => 1,
					'status'             => 'optimized',
					'hash'               => $hash,
					'last_optimized_at'  => $now,
				)
			);

			Database::log_run(
				array(
					'attachment_id'  => $attachment_id,
					'optimizer_used' => 'duplicate-reuse',
					'format_used'    => $duplicate->file_path ? strtolower( pathinfo( $duplicate->file_path, PATHINFO_EXTENSION ) ) : 'unknown',
					'quality_used'   => 0,
					'saved_bytes'    => $saved_bytes,
					'saved_percent'  => $original_size > 0 ? round( ( $saved_bytes / $original_size ) * 100, 2 ) : 0,
					'status'         => 'success',
					'error_message'  => __( 'Duplicate detected. Optimization reused.', 'ffmpeg-media-optimizer' ),
					'duration'       => $duration,
					'started_at'     => $now,
					'finished_at'    => $now,
				)
			);

			Logger::log( sprintf( 'Duplicate detected for attachment ID %d. Reused optimized file from ID %d. Saved %d bytes.', $attachment_id, $duplicate->attachment_id, $saved_bytes ), 'info' );

			return array(
				'success' => true,
				'saved'   => $saved_bytes,
				'message' => __( 'Duplicate detected. Optimization reused.', 'ffmpeg-media-optimizer' ),
			);
		}

		return array(
			'success' => false,
			'error'   => __( 'Failed to copy duplicate optimized file.', 'ffmpeg-media-optimizer' ),
		);
	}

	/**
	 * Dynamically resolve binary path using system which/where utilities.
	 */
	public static function resolve_binary_path( $name ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return '';
		}

		$is_win = ( strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN' );
		$cmd    = $is_win ? 'where.exe ' . escapeshellarg( $name ) : 'which ' . escapeshellarg( $name );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = @proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return '';
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		$path = trim( $stdout );
		if ( ! empty( $path ) ) {
			$lines = explode( "\n", str_replace( "\r", "", $path ) );
			$first_path = trim( $lines[0] );
			if ( file_exists( $first_path ) ) {
				return $first_path;
			}
		}

		return '';
	}
}
