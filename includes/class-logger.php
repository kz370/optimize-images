<?php
/**
 * Secure Logger class.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 *
 * Log events to a file with a .php extension containing a die/exit header.
 */
class Logger {

	/**
	 * Init the logging folder and index file.
	 */
	public static function init() {
		$dir = self::get_log_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Ensure there's an index.php preventing directory listing
		$index_file = $dir . '/index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, '<?php exit;' );
		}

		// Check and write .htaccess
		$htaccess_file = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, 'deny from all' );
		}
	}

	/**
	 * Get absolute path to logs directory.
	 *
	 * @return string Log folder path.
	 */
	public static function get_log_dir() {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/optimize-images-logs';
	}

	/**
	 * Get absolute path to the main log file.
	 *
	 * @return string Log file path.
	 */
	public static function get_log_file() {
		return self::get_log_dir() . '/ffmpeg-optimizer.log.php';
	}

	/**
	 * Write a message to the log file.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level ('info', 'error', 'debug').
	 */
	public static function log( $message, $level = 'info' ) {
		$settings = Admin\Settings::get_settings();

		// Check if logging is enabled.
		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		// Check if debug level is requested and debug mode is on.
		if ( 'debug' === $level && empty( $settings['debug_mode'] ) ) {
			return;
		}

		$log_file = self::get_log_file();
		$exists   = file_exists( $log_file );

		$now     = current_time( 'mysql', 1 );
		$entry   = sprintf( "[%s] [%s] %s\n", $now, strtoupper( $level ), $message );
		$content = '';

		if ( ! $exists ) {
			$content .= "<?php exit; ?>\n";
		}

		$content .= $entry;

		// Append securely.
		file_put_contents( $log_file, $content, FILE_APPEND );
	}

	/**
	 * Read the logs.
	 *
	 * @param int $lines Number of trailing lines to read.
	 * @return string Log contents.
	 */
	public static function read_logs( $lines = 100 ) {
		$log_file = self::get_log_file();
		if ( ! file_exists( $log_file ) ) {
			return esc_html__( 'No logs recorded yet.', 'optimize-images' );
		}

		$content = file_get_contents( $log_file );
		if ( ! $content ) {
			return '';
		}

		// Remove the PHP exit header.
		$content = str_replace( "<?php exit; ?>\n", '', $content );

		// Split into lines.
		$file_lines = explode( "\n", trim( $content ) );
		if ( count( $file_lines ) > $lines ) {
			$file_lines = array_slice( $file_lines, -$lines );
		}

		return implode( "\n", $file_lines );
	}

	/**
	 * Clear the log file.
	 */
	public static function clear_logs() {
		$log_file = self::get_log_file();
		if ( file_exists( $log_file ) ) {
			wp_delete_file( $log_file );
		}
		self::init();
	}
}
