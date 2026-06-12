<?php
/**
 * Bulk page AJAX and layouts controller.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer\Admin;

use FfmpegMediaOptimizer\Scanner;
use FfmpegMediaOptimizer\Database;
use FfmpegMediaOptimizer\Background_Processor;
use FfmpegMediaOptimizer\Restorer;
use FfmpegMediaOptimizer\Logger;
use FfmpegMediaOptimizer\Optimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bulk_Page
 *
 * Renders bulk optimize pages and serves AJAX actions.
 */
class Bulk_Page {

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_ffmpeg_media_bulk_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_ffmpeg_media_bulk_control', array( $this, 'ajax_control' ) );
		add_action( 'wp_ajax_ffmpeg_media_bulk_stats', array( $this, 'ajax_stats' ) );
		add_action( 'wp_ajax_ffmpeg_media_bulk_process', array( $this, 'ajax_process' ) );
		add_action( 'wp_ajax_ffmpeg_media_bulk_restore', array( $this, 'ajax_restore_all' ) );
		add_action( 'wp_ajax_ffmpeg_media_clear_logs', array( $this, 'ajax_clear_logs' ) );

		// Secure agency report download handler (Feature 11)
		add_action( 'admin_init', array( $this, 'handle_report_download' ) );
	}

	/**
	 * Mount sub menu under Media.
	 */
	public function add_menu_page() {
		add_media_page(
			esc_html__( 'FFmpeg Bulk Optimizer', 'ffmpeg-media-optimizer' ),
			esc_html__( 'Bulk Optimization', 'ffmpeg-media-optimizer' ),
			'manage_options',
			'ffmpeg-media-bulk',
			array( $this, 'render_bulk_page' )
		);
	}

	/**
	 * Renders Bulk dashboard layouts.
	 */
	public function render_bulk_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ffmpeg-media-optimizer' ) );
		}

		// Force re-detection of binaries when visiting Bulk page
		delete_transient( 'fmo_detected_binaries' );

		require_once FFMPEG_MEDIA_OPTIMIZER_PATH . 'admin/views/bulk-page.php';
	}

	/**
	 * AJAX endpoint to scan files in batches.
	 */
	public function ajax_scan() {
		check_ajax_referer( 'ffmpeg_media_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden', 'ffmpeg-media-optimizer' ) ), 403 );
		}

		$offset  = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$scanner = new Scanner();
		$limit   = 500;

		$scanned = $scanner->scan_batch( $offset, $limit );

		wp_send_json_success(
			array(
				'scanned' => $scanned,
				'done'    => ( $scanned < $limit ),
				'offset'  => $offset + $limit,
			)
		);
	}

	/**
	 * AJAX endpoint for managing queue states (start, pause, resume, stop, restart, clear, retry_failed).
	 */
	public function ajax_control() {
		check_ajax_referer( 'ffmpeg_media_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden', 'ffmpeg-media-optimizer' ) ), 403 );
		}

		$operation = isset( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
		$scope     = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'all';
		$processor = new Background_Processor();

		switch ( $operation ) {
			case 'start':
				$processor->start( $scope );
				break;
			case 'pause':
				$processor->pause();
				break;
			case 'resume':
				$processor->resume();
				break;
			case 'stop':
			case 'clear':
				$processor->stop();
				break;
			case 'restart':
				$processor->stop();
				$processor->start( $scope );
				break;
			case 'retry_failed':
				global $wpdb;
				$table = Database::get_images_table();
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE status = %s", 'pending', 'failed' ) );
				$processor->start( $scope );
				break;
			default:
				wp_send_json_error( array( 'error' => __( 'Invalid control operation.', 'ffmpeg-media-optimizer' ) ) );
		}

		wp_send_json_success( array( 'state' => Background_Processor::get_queue_state() ) );
	}

	/**
	 * AJAX endpoint to fetch status metrics, daily/monthly savings timelines and logs.
	 */
	public function ajax_stats() {
		check_ajax_referer( 'ffmpeg_media_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden', 'ffmpeg-media-optimizer' ) ), 403 );
		}

		$scope = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'all';
		$stats = Database::get_dashboard_stats( $scope );
		$queue = Background_Processor::get_queue_state();
		$logs  = Logger::read_logs( 50 );

		// Load timeline data for charts (Feature 3)
		$daily   = Database::get_daily_savings( 30 );
		$monthly = Database::get_monthly_savings( 12 );

		wp_send_json_success(
			array(
				'stats'       => $stats,
				'queue'       => $queue,
				'daily'       => $daily,
				'monthly'     => $monthly,
				'formatted'   => array(
					'bytes_saved'     => size_format( $stats['bytes_saved'] ),
					'duplicate_saved' => size_format( $stats['duplicate_saved'] ),
				),
				'logs'        => esc_html( $logs ),
			)
		);
	}

	/**
	 * AJAX endpoint to process a single item from the queue actively.
	 */
	public function ajax_process() {
		check_ajax_referer( 'ffmpeg_media_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden', 'ffmpeg-media-optimizer' ) ), 403 );
		}

		$scope = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'all';
		$state = Background_Processor::get_queue_state();

		if ( 'running' !== $state['status'] ) {
			wp_send_json_success(
				array(
					'state'     => $state,
					'processed' => false,
				)
			);
		}

		// Verify timezone window
		if ( ! Background_Processor::is_within_allowed_window() ) {
			wp_send_json_error( array( 'error' => __( 'Outside of allowed timezone window.', 'ffmpeg-media-optimizer' ) ) );
		}

		// Fetch the NEXT 1 pending ID
		$pending_ids = Database::get_pending_ids( 1, $scope );
		if ( empty( $pending_ids ) ) {
			// No more jobs, complete the queue
			$processor = new Background_Processor();
			$processor->stop();
			$state           = Background_Processor::get_queue_state();
			$state['status'] = 'idle';
			Background_Processor::save_queue_state( $state );

			wp_send_json_success(
				array(
					'state'     => $state,
					'processed' => false,
					'completed' => true,
				)
			);
		}

		$id = $pending_ids[0];

		// Recovery Checkpoint: record current job
		$state['current_job'] = $id;
		Background_Processor::save_queue_state( $state );
		update_option( 'fmo_queue_checkpoint', time() );

		// Run optimization on this single attachment
		$optimizer = new Optimizer();
		$res = $optimizer->optimize_attachment( $id );

		// Increment processed count
		$state = Background_Processor::get_queue_state();
		$state['processed_count']++;
		Background_Processor::save_queue_state( $state );

		wp_send_json_success(
			array(
				'state'     => $state,
				'processed' => true,
				'res'       => $res,
			)
		);
	}

	/**
	 * AJAX endpoint to bulk restore originals.
	 */
	public function ajax_restore_all() {
		check_ajax_referer( 'ffmpeg_media_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden', 'ffmpeg-media-optimizer' ) ), 403 );
		}

		$restorer = new Restorer();
		$count    = $restorer->restore_all();

		Database::reset_all_to_pending();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: count of restored files */
					__( 'Restore complete! Restored %d images to original files.', 'ffmpeg-media-optimizer' ),
					$count
				),
			)
		);
	}

	/**
	 * AJAX endpoint to clear log records.
	 */
	public function ajax_clear_logs() {
		check_ajax_referer( 'ffmpeg_media_bulk_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden', 'ffmpeg-media-optimizer' ) ), 403 );
		}

		Logger::clear_logs();
		wp_send_json_success( array( 'message' => __( 'Logs cleared.', 'ffmpeg-media-optimizer' ) ) );
	}

	/**
	 * Secure Report Exporter Download (Feature 11).
	 */
	public function handle_report_download() {
		if ( ! isset( $_GET['action'] ) || 'fmo_agency_report' !== $_GET['action'] ) {
			return;
		}

		check_admin_referer( 'fmo_agency_report_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'ffmpeg-media-optimizer' ) );
		}

		$format     = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv';
		$start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
		$end_date   = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';

		global $wpdb;
		$runs_table = Database::get_runs_table();

		// Build date filter query
		$where = "status = 'success'";
		if ( ! empty( $start_date ) ) {
			$where .= $wpdb->prepare( " AND finished_at >= %s", $start_date . ' 00:00:00' );
		}
		if ( ! empty( $end_date ) ) {
			$where .= $wpdb->prepare( " AND finished_at <= %s", $end_date . ' 23:59:59' );
		}

		// Retrieve historical run data
		$results = $wpdb->get_results( "SELECT * FROM {$runs_table} WHERE {$where} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Compute metrics summary
		$summary = Database::get_dashboard_stats( 'all' );

		$filename = 'ffmpeg-media-optimizer-report-' . current_time( 'Y-md-His' ) . '.' . $format;

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			
			$payload = array(
				'summary' => $summary,
				'runs'    => $results,
			);
			echo wp_json_encode( $payload );
			exit;
		} else {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			$output = fopen( 'php://output', 'w' );
			
			// Summary metrics block
			fputcsv( $output, array( __( 'Summary Performance Metrics', 'ffmpeg-media-optimizer' ) ) );
			fputcsv( $output, array( __( 'Total Images', 'ffmpeg-media-optimizer' ), __( 'Optimized Images', 'ffmpeg-media-optimizer' ), __( 'Failed Images', 'ffmpeg-media-optimizer' ), __( 'Total Savings (Bytes)', 'ffmpeg-media-optimizer' ), __( 'Duplicate Savings (Bytes)', 'ffmpeg-media-optimizer' ), __( 'Average Savings Percent', 'ffmpeg-media-optimizer' ) ) );
			fputcsv( $output, array( $summary['total'], $summary['optimized'], $summary['failed'], $summary['bytes_saved'], $summary['duplicate_saved'], $summary['average_savings'] . '%' ) );
			fputcsv( $output, array() );

			// Details list block
			fputcsv( $output, array( __( 'Conversion Run Index', 'ffmpeg-media-optimizer' ), __( 'Attachment ID', 'ffmpeg-media-optimizer' ), __( 'Optimizer', 'ffmpeg-media-optimizer' ), __( 'Format', 'ffmpeg-media-optimizer' ), __( 'Quality Used', 'ffmpeg-media-optimizer' ), __( 'Saved Bytes', 'ffmpeg-media-optimizer' ), __( 'Saved Percent', 'ffmpeg-media-optimizer' ), __( 'Duration (s)', 'ffmpeg-media-optimizer' ), __( 'Completed Date', 'ffmpeg-media-optimizer' ) ) );

			foreach ( $results as $run ) {
				fputcsv(
					$output,
					array(
						$run->id,
						$run->attachment_id,
						$run->optimizer_used,
						$run->format_used,
						$run->quality_used,
						$run->saved_bytes,
						$run->saved_percent,
						$run->duration,
						$run->finished_at,
					)
				);
			}

			fclose( $output );
			exit;
		}
	}
}
