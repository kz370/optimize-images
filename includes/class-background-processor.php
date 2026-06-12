<?php
/**
 * Background processing queue coordinator.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Background_Processor
 *
 * Handles task scheduling, queue storage, WooCommerce scopes, and crash recovery.
 */
class Background_Processor {

	/**
	 * Register actions for background runs.
	 */
	public function register_hooks() {
		add_action( 'ffmpeg_media_optimizer_run_queue', array( $this, 'process_queue' ) );
		add_action( 'ffmpeg_media_optimizer_cron', array( $this, 'process_queue' ) );
	}

	/**
	 * Get the current queue state.
	 *
	 * @return array State parameters.
	 */
	public static function get_queue_state() {
		$defaults = array(
			'status'            => 'idle', // 'idle', 'running', 'paused', 'stopped'
			'current_job'       => 0,
			'processed_count'   => 0,
			'total_jobs'        => 0,
			'scope'             => 'all',  // 'all', 'wc', 'products', 'galleries', 'categories'
			'current_file'      => '',
			'current_orig_size' => '',
			'current_opt_size'  => '',
		);
		$state = get_option( 'ffmpeg_media_optimizer_queue_state', array() );
		$state = array_merge( $defaults, (array) $state );

		// Check for unexpected interruption / stalls (Feature 4)
		if ( 'running' === $state['status'] && self::check_interrupted() ) {
			$state['interrupted'] = true;
		} else {
			$state['interrupted'] = false;
		}

		return $state;
	}

	/**
	 * Save the current queue state.
	 *
	 * @param array $state State parameters.
	 */
	public static function save_queue_state( $state ) {
		// Remove runtime flags before saving
		unset( $state['interrupted'] );
		update_option( 'ffmpeg_media_optimizer_queue_state', $state );
	}

	/**
	 * Check if the background execution has stalled/interrupted.
	 *
	 * @return bool True if stalled.
	 */
	public static function check_interrupted() {
		$checkpoint = get_option( 'fmo_queue_checkpoint' );
		if ( ! $checkpoint ) {
			return false;
		}
		// If last checkpoint is older than 5 minutes (300 seconds), it has stalled
		return ( ( time() - (int) $checkpoint ) > 300 );
	}

	/**
	 * Enqueue an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function enqueue_attachment( $attachment_id ) {
		$state = self::get_queue_state();

		// Add to queue if running or idle.
		if ( 'running' === $state['status'] || 'idle' === $state['status'] ) {
			// If WooCommerce scope is selected, verify file belongs to scope
			if ( 'all' !== $state['scope'] ) {
				$scope_ids = Database::get_wc_attachment_ids( $state['scope'] );
				if ( ! in_array( $attachment_id, $scope_ids, true ) ) {
					return;
				}
			}

			$state['total_jobs']++;
			self::save_queue_state( $state );

			$this->trigger_runner();
		}
	}

	/**
	 * Start the background queue processing with scope.
	 *
	 * @param string $scope Optimization scope.
	 */
	public function start( $scope = 'all' ) {
		$state           = self::get_queue_state();
		$state['status'] = 'running';
		$state['scope']  = $scope;

		// Fetch total pending items in scope
		$pending_ids = Database::get_pending_ids( 999999, $scope );

		$state['total_jobs']      = count( $pending_ids );
		$state['processed_count'] = 0;
		$state['current_job']     = 0;

		self::save_queue_state( $state );

		// Set initial recovery checkpoint
		update_option( 'fmo_queue_checkpoint', time() );

		$this->trigger_runner();
	}

	/**
	 * Pause the background queue processing.
	 */
	public function pause() {
		$state           = self::get_queue_state();
		$state['status'] = 'paused';
		$state['current_file']      = '';
		$state['current_orig_size'] = '';
		$state['current_opt_size']  = '';
		self::save_queue_state( $state );
		delete_option( 'fmo_queue_checkpoint' );
	}

	/**
	 * Resume the background queue processing (also handles crash recovery).
	 */
	public function resume() {
		$state           = self::get_queue_state();
		$state['status'] = 'running';
		self::save_queue_state( $state );

		// Update checkpoint to avoid instant crash loop detection
		update_option( 'fmo_queue_checkpoint', time() );

		$this->trigger_runner();
	}

	/**
	 * Stop and clear queue processing.
	 */
	public function stop() {
		$state                    = self::get_queue_state();
		$state['status']          = 'idle';
		$state['current_job']     = 0;
		$state['processed_count'] = 0;
		$state['total_jobs']      = 0;
		$state['current_file']      = '';
		$state['current_orig_size'] = '';
		$state['current_opt_size']  = '';
		self::save_queue_state( $state );

		delete_option( 'fmo_queue_checkpoint' );
	}

	/**
	 * Trigger the asynchronous runner.
	 */
	public function trigger_runner() {
		if ( class_exists( 'ActionScheduler' ) ) {
			if ( ! as_next_scheduled_action( 'ffmpeg_media_optimizer_run_queue' ) ) {
				as_enqueue_async_action( 'ffmpeg_media_optimizer_run_queue' );
			}
		} else {
			if ( ! wp_next_scheduled( 'ffmpeg_media_optimizer_run_queue' ) ) {
				wp_schedule_single_event( time(), 'ffmpeg_media_optimizer_run_queue' );
			}
		}
	}

	/**
	 * Process a single batch from the queue.
	 */
	public function process_queue() {
		$state = self::get_queue_state();
		if ( 'running' !== $state['status'] ) {
			return;
		}

		// 1. Verify scheduled window (Feature 8)
		if ( ! self::is_within_allowed_window() ) {
			Logger::log( 'Queue processing suspended: outside of timezone optimization window.', 'info' );
			return;
		}

		// Update checkpoints periodically
		update_option( 'fmo_queue_checkpoint', time() );

		$settings   = Admin\Settings::get_settings();
		$batch_size = isset( $settings['max_concurrent_jobs'] ) ? (int) $settings['max_concurrent_jobs'] : 2;
		if ( $batch_size <= 0 ) {
			$batch_size = 2;
		}

		$pending_ids = Database::get_pending_ids( $batch_size, $state['scope'] );
		if ( empty( $pending_ids ) ) {
			// Queue is fully complete.
			$this->stop();
			$state           = self::get_queue_state();
			$state['status'] = 'idle';
			self::save_queue_state( $state );
			Logger::log( 'Background queue finished processing all pending items.', 'info' );
			return;
		}

		$optimizer = new Optimizer();

		foreach ( $pending_ids as $id ) {
			// Check if state was paused/stopped by another request during this loop
			$current_state = self::get_queue_state();
			if ( 'running' !== $current_state['status'] ) {
				break;
			}

			// Recovery Checkpoint: record checkpoint file/current job
			$current_state['current_job'] = $id;
			self::save_queue_state( $current_state );
			update_option( 'fmo_queue_checkpoint', time() );

			// Run optimization
			$optimizer->optimize_attachment( $id );

			// Update processed count
			$current_state = self::get_queue_state();
			$current_state['processed_count']++;
			self::save_queue_state( $current_state );
		}

		// Reschedule next execution if still running
		$final_state = self::get_queue_state();
		if ( 'running' === $final_state['status'] ) {
			$this->trigger_runner();
		}
	}

	/**
	 * Checks if the current time is within the allowed window (Feature 8).
	 * Supports site's standard timezone configuration.
	 *
	 * @return bool True if permitted to run.
	 */
	public static function is_within_allowed_window() {
		$settings = Admin\Settings::get_settings();
		if ( empty( $settings['night_schedule'] ) ) {
			return true;
		}

		$start = isset( $settings['night_start'] ) ? $settings['night_start'] : '22:00';
		$end   = isset( $settings['night_end'] ) ? $settings['night_end'] : '06:00';

		// current_time('H:i') handles WordPress timezones settings automatically
		$current_time = current_time( 'H:i' );

		if ( $start < $end ) {
			return ( $current_time >= $start && $current_time <= $end );
		} else {
			return ( $current_time >= $start || $current_time <= $end );
		}
	}
}
