<?php
/**
 * Plugin health checker and diagnostics.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Health_Check
 *
 * Runs background scans of server environment capabilities and outputs warnings/remedies.
 */
class Health_Check {

	/**
	 * Register hooks for admin health notices.
	 */
	public static function register_hooks() {
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'display_notices' ) );
			add_action( 'wp_ajax_ffmpeg_media_dismiss_notice', array( __CLASS__, 'dismiss_notice' ) );
		}
	}

	/**
	 * Gather all diagnostic issues.
	 *
	 * @return array List of issues with details (status, message, fix).
	 */
	public static function check_health() {
		$issues   = array();
		$binaries = Optimizer::detect_binaries();

		// 1. FFmpeg availability
		if ( empty( $binaries['ffmpeg'] ) ) {
			$issues['ffmpeg_missing'] = array(
				'status'  => 'critical',
				'message' => __( 'FFmpeg binary is missing or not executable.', 'ffmpeg-media-optimizer' ),
				'fix'     => __( 'Install FFmpeg on your server via package manager (e.g. apt-get install ffmpeg) or set its custom absolute path in Settings.', 'ffmpeg-media-optimizer' ),
			);
		} else {
			// check version
			$v = $binaries['ffmpeg']['version'];
			if ( self::is_ffmpeg_version_outdated( $v ) ) {
				$issues['ffmpeg_outdated'] = array(
					'status'  => 'warning',
					'message' => sprintf( __( 'FFmpeg version (%s) is outdated.', 'ffmpeg-media-optimizer' ), $v ),
					'fix'     => __( 'Upgrade FFmpeg to version 4.0.0 or higher to ensure compatibility with modern filters.', 'ffmpeg-media-optimizer' ),
				);
			}
		}

		// 2. Binary optimizers missing checks (Removed - relying only on FFmpeg)


		// 3. Upload directory permissions
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) || ! is_writable( $upload_dir['basedir'] ) ) {
			$issues['uploads_permissions'] = array(
				'status'  => 'critical',
				'message' => __( 'Uploads directory is not writable.', 'ffmpeg-media-optimizer' ),
				'fix'     => __( 'Adjust directory permissions (CHMOD 755 or 775) on wp-content/uploads/ to allow writing optimized files.', 'ffmpeg-media-optimizer' ),
			);
		}

		// 4. Backup directory permissions
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$backups_dir = $upload_dir['basedir'] . '/.ffmpeg-media-optimizer-backups';
			if ( is_dir( $backups_dir ) && ! is_writable( $backups_dir ) ) {
				$issues['backups_permissions'] = array(
					'status'  => 'critical',
					'message' => __( 'Backups directory is not writable.', 'ffmpeg-media-optimizer' ),
					'fix'     => __( 'Adjust directory permissions on wp-content/uploads/.ffmpeg-media-optimizer-backups/ to restore images.', 'ffmpeg-media-optimizer' ),
				);
			}
		}

		// 5. Low disk space
		if ( ! empty( $upload_dir['basedir'] ) && function_exists( 'disk_free_space' ) ) {
			$free_space = @disk_free_space( $upload_dir['basedir'] );
			if ( $free_space !== false && $free_space < 52428800 ) { // Less than 50MB
				$issues['low_disk'] = array(
					'status'  => 'critical',
					'message' => __( 'Disk space is critically low (under 50MB free).', 'ffmpeg-media-optimizer' ),
					'fix'     => __( 'Free up server storage or upgrade disk quota. Background processing is suspended.', 'ffmpeg-media-optimizer' ),
				);
			}
		}

		// 6. Disabled WP-Cron
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && ! class_exists( 'ActionScheduler' ) ) {
			$issues['cron_disabled'] = array(
				'status'  => 'warning',
				'message' => __( 'WP-Cron is disabled and Action Scheduler is unavailable.', 'ffmpeg-media-optimizer' ),
				'fix'     => __( 'Enable WP-Cron in wp-config.php or run background cron schedules using server system crontabs.', 'ffmpeg-media-optimizer' ),
			);
		}

		// 7. Action Scheduler availability
		if ( ! class_exists( 'ActionScheduler' ) ) {
			$issues['action_scheduler'] = array(
				'status'  => 'warning',
				'message' => __( 'Action Scheduler is not installed.', 'ffmpeg-media-optimizer' ),
				'fix'     => __( 'Install WooCommerce or Action Scheduler plugin to enable reliable background batch processing.', 'ffmpeg-media-optimizer' ),
			);
		}

		// 8. PHP memory limit
		$mem_limit = ini_get( 'memory_limit' );
		if ( $mem_limit ) {
			$bytes = self::parse_size( $mem_limit );
			if ( $bytes > 0 && $bytes < 134217728 ) { // Less than 128M
				$issues['low_memory'] = array(
					'status'  => 'warning',
					'message' => sprintf( __( 'PHP memory limit is low (%s).', 'ffmpeg-media-optimizer' ), $mem_limit ),
					'fix'     => __( 'Increase memory_limit inside php.ini to 256M or higher to prevent crashes.', 'ffmpeg-media-optimizer' ),
				);
			}
		}

		return $issues;
	}

	/**
	 * Display diagnostic notices inside WP admin.
	 */
	public static function display_notices() {
		$screen = get_current_screen();
		if ( ! $screen || ( ! str_contains( $screen->id, 'ffmpeg-media' ) && 'dashboard' !== $screen->id ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$issues = self::check_health();
		if ( empty( $issues ) ) {
			return;
		}

		$dismissed = (array) get_user_meta( get_current_user_id(), 'ffmpeg_media_dismissed_notices', true );

		foreach ( $issues as $key => $issue ) {
			if ( in_array( $key, $dismissed, true ) ) {
				continue;
			}

			// Map status to notice class
			$severity = 'info';
			if ( 'critical' === $issue['status'] ) {
				$severity = 'error';
			} elseif ( 'warning' === $issue['status'] ) {
				$severity = 'warning';
			}

			$class = 'notice notice-' . $severity;
			if ( 'critical' !== $issue['status'] ) {
				$class .= ' is-dismissible';
			}

			printf(
				'<div class="%1$s" data-notice-key="%2$s"><p><strong>%3$s:</strong> %4$s <br/><em>%5$s:</em> %6$s</p></div>',
				esc_attr( $class ),
				esc_attr( $key ),
				esc_html__( 'FFmpeg Media Optimizer', 'ffmpeg-media-optimizer' ),
				esc_html( $issue['message'] ),
				esc_html__( 'Suggested Fix', 'ffmpeg-media-optimizer' ),
				esc_html( $issue['fix'] )
			);
		}

		?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$(document).on('click', '.notice-dismiss', function() {
					var $notice = $(this).closest('.notice');
					var key = $notice.attr('data-notice-key');
					if (key) {
						$.post(ajaxurl, {
							action: 'ffmpeg_media_dismiss_notice',
							key: key,
							nonce: '<?php echo esc_js( wp_create_nonce( 'ffmpeg_media_dismiss_nonce' ) ); ?>'
						});
					}
				});
			});
		</script>
		<?php
	}

	/**
	 * AJAX endpoint to dismiss notices.
	 */
	public static function dismiss_notice() {
		check_ajax_referer( 'ffmpeg_media_dismiss_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';
		if ( $key ) {
			$user_id   = get_current_user_id();
			$dismissed = (array) get_user_meta( $user_id, 'ffmpeg_media_dismissed_notices', true );
			if ( ! in_array( $key, $dismissed, true ) ) {
				$dismissed[] = $key;
				update_user_meta( $user_id, 'ffmpeg_media_dismissed_notices', $dismissed );
			}
			wp_send_json_success();
		}

		wp_send_json_error( 'Invalid Key' );
	}

	/**
	 * Convert shorthand memory notations to bytes.
	 */
	private static function parse_size( $size ) {
		$unit = preg_replace( '/[^bkmgtpezy]/i', '', $size );
		$num  = preg_replace( '/[^0-9.]/', '', $size );
		if ( $unit ) {
			return (int) round( $num * pow( 1024, stripos( 'bkmgtpezy', $unit[0] ) ) );
		}
		return (int) $num;
	}

	/**
	 * Check if FFmpeg version is outdated.
	 */
	private static function is_ffmpeg_version_outdated( $version ) {
		if ( 'unknown' === $version ) {
			return true;
		}
		if ( 'detected' === $version ) {
			return false;
		}

		// Development builds like N-119072-g5fac8d062d-20250329 are up-to-date
		if ( str_starts_with( $version, 'N-' ) || str_contains( $version, 'git-' ) || preg_match( '/\b(20\d{2})\b/', $version ) ) {
			return false;
		}

		// Clean version string to keep only digits/dots for comparison if possible
		if ( preg_match( '/^(\d+\.\d+(\.\d+)?)/', $version, $matches ) ) {
			return version_compare( $matches[1], '4.0.0', '<' );
		}

		return version_compare( $version, '4.0.0', '<' );
	}
}
