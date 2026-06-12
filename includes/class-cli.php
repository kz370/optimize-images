<?php
/**
 * WP-CLI Commands coordinator.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cli
 *
 * Exposes WP-CLI commands under "wp fmo".
 */
class Cli {

	/**
	 * Register CLI commands if WP-CLI is present.
	 */
	public static function register_commands() {
		if ( class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'fmo', __CLASS__ );
		}
	}

	/**
	 * Scan the WordPress Media Library.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmo scan
	 *
	 * @param array $args       Command line arguments.
	 * @param array $assoc_args Command line associative arguments.
	 */
	public function scan( $args, $assoc_args ) {
		\WP_CLI::log( __( 'Scanning Media Library...', 'ffmpeg-media-optimizer' ) );

		$scanner = new Scanner();
		$offset  = 0;
		$limit   = 500;
		$total   = 0;

		while ( true ) {
			$scanned = $scanner->scan_batch( $offset, $limit );
			if ( 0 === $scanned ) {
				break;
			}
			$total  += $scanned;
			$offset += $limit;
			\WP_CLI::log( sprintf( __( 'Scanned %d attachments...', 'ffmpeg-media-optimizer' ), $offset ) );
		}

		\WP_CLI::success( sprintf( __( 'Scan completed! Found %d new/updated image attachments.', 'ffmpeg-media-optimizer' ), $total ) );
	}

	/**
	 * Run optimization on images.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : Optimization scope ('all', 'woocommerce', 'products', 'galleries', 'categories').
	 *
	 * [--ids=<attachment_ids>]
	 * : Comma-separated list of attachment IDs to optimize.
	 *
	 * [--batch-size=<number>]
	 * : Number of items to process.
	 *
	 * [--resume]
	 * : Resume queue from previous interrupted checkpoint.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmo optimize --ids=12,34,56
	 *     wp fmo optimize --scope=woocommerce --batch-size=50
	 *     wp fmo optimize --resume
	 *
	 * @param array $args       Command line arguments.
	 * @param array $assoc_args Command line associative arguments.
	 */
	public function optimize( $args, $assoc_args ) {
		$optimizer = new Optimizer();

		// Case 1: Specific IDs
		if ( isset( $assoc_args['ids'] ) ) {
			$ids = array_map( 'intval', explode( ',', $assoc_args['ids'] ) );
			\WP_CLI::log( sprintf( __( 'Optimizing %d targeted attachments...', 'ffmpeg-media-optimizer' ), count( $ids ) ) );
			
			$success = 0;
			$failed  = 0;
			$progress = \WP_CLI\Utils\make_progress_bar( __( 'Processing', 'ffmpeg-media-optimizer' ), count( $ids ) );

			foreach ( $ids as $id ) {
				$scanner = new Scanner();
				$scanner->scan_attachment( $id );

				$res = $optimizer->optimize_attachment( $id );
				if ( $res['success'] ) {
					$success++;
				} else {
					$failed++;
				}
				$progress->tick();
			}
			$progress->finish();
			\WP_CLI::success( sprintf( __( 'Completed! Success: %1$d, Failed: %2$d', 'ffmpeg-media-optimizer' ), $success, $failed ) );
			return;
		}

		// Case 2: Resume queue
		if ( isset( $assoc_args['resume'] ) ) {
			\WP_CLI::log( __( 'Resuming queue from checkpoint...', 'ffmpeg-media-optimizer' ) );
			$processor = new Background_Processor();
			$processor->resume();
			\WP_CLI::success( __( 'Queue resumed in background.', 'ffmpeg-media-optimizer' ) );
			return;
		}

		// Case 3: Batch optimize based on scope
		$scope = isset( $assoc_args['scope'] ) ? sanitize_key( $assoc_args['scope'] ) : 'all';
		if ( 'woocommerce' === $scope ) {
			$scope = 'wc';
		}

		$limit = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 50;
		\WP_CLI::log( sprintf( __( 'Fetching pending images (scope: %1$s, limit: %2$d)...', 'ffmpeg-media-optimizer' ), $scope, $limit ) );

		$pending = Database::get_pending_ids( $limit, $scope );
		if ( empty( $pending ) ) {
			\WP_CLI::success( __( 'No pending images in this scope.', 'ffmpeg-media-optimizer' ) );
			return;
		}

		$success = 0;
		$failed  = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( __( 'Optimizing', 'ffmpeg-media-optimizer' ), count( $pending ) );

		foreach ( $pending as $id ) {
			$res = $optimizer->optimize_attachment( $id );
			if ( $res['success'] ) {
				$success++;
			} else {
				$failed++;
			}
			$progress->tick();
		}
		$progress->finish();

		\WP_CLI::success( sprintf( __( 'Batch completed! Success: %1$d, Failed: %2$d', 'ffmpeg-media-optimizer' ), $success, $failed ) );
	}

	/**
	 * Display plugin queue and database statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmo status
	 *
	 * @param array $args       Command line arguments.
	 * @param array $assoc_args Command line associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$stats = Database::get_dashboard_stats( 'all' );

		\WP_CLI::line( '------------------------------------------' );
		\WP_CLI::line( __( ' FFmpeg Media Optimizer Status', 'ffmpeg-media-optimizer' ) );
		\WP_CLI::line( '------------------------------------------' );
		\WP_CLI::line( sprintf( __( ' Total Library Images: %d', 'ffmpeg-media-optimizer' ), $stats['total'] ) );
		\WP_CLI::line( sprintf( __( ' Optimized Images:     %d', 'ffmpeg-media-optimizer' ), $stats['optimized'] ) );
		\WP_CLI::line( sprintf( __( ' Pending Optimization: %d', 'ffmpeg-media-optimizer' ), $stats['pending'] ) );
		\WP_CLI::line( sprintf( __( ' Failed Optimization:  %d', 'ffmpeg-media-optimizer' ), $stats['failed'] ) );
		\WP_CLI::line( sprintf( __( ' Total Space Saved:    %s', 'ffmpeg-media-optimizer' ), size_format( $stats['bytes_saved'] ) ) );
		\WP_CLI::line( sprintf( __( ' Duplicate Savings:    %s', 'ffmpeg-media-optimizer' ), size_format( $stats['duplicate_saved'] ) ) );
		\WP_CLI::line( '------------------------------------------' );

		// Check health issues.
		$issues = Health_Check::check_health();
		if ( ! empty( $issues ) ) {
			\WP_CLI::line( __( ' Health warnings detected:', 'ffmpeg-media-optimizer' ) );
			foreach ( $issues as $issue ) {
				$label = strtoupper( $issue['status'] );
				if ( 'CRITICAL' === $label ) {
					\WP_CLI::error( $issue['message'] . ' [Fix: ' . $issue['fix'] . ']', false );
				} else {
					\WP_CLI::warning( $issue['message'] . ' [Fix: ' . $issue['fix'] . ']' );
				}
			}
			\WP_CLI::line( '------------------------------------------' );
		}
	}

	/**
	 * Monitor background queue status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmo queue
	 *
	 * @param array $args       Command line arguments.
	 * @param array $assoc_args Command line associative arguments.
	 */
	public function queue( $args, $assoc_args ) {
		$state = Background_Processor::get_queue_state();
		\WP_CLI::line( '------------------------------------------' );
		\WP_CLI::line( __( ' Background Queue Status', 'ffmpeg-media-optimizer' ) );
		\WP_CLI::line( '------------------------------------------' );
		\WP_CLI::line( sprintf( __( ' Queue State:      %s', 'ffmpeg-media-optimizer' ), strtoupper( $state['status'] ) ) );
		\WP_CLI::line( sprintf( __( ' Active Scope:     %s', 'ffmpeg-media-optimizer' ), strtoupper( $state['scope'] ) ) );
		\WP_CLI::line( sprintf( __( ' Progress Metrics: %1$d/%2$d processed', 'ffmpeg-media-optimizer' ), $state['processed_count'], $state['total_jobs'] ) );
		\WP_CLI::line( sprintf( __( ' Stalled/Crashed:  %s', 'ffmpeg-media-optimizer' ), $state['interrupted'] ? 'YES' : 'NO' ) );
		\WP_CLI::line( '------------------------------------------' );
	}

	/**
	 * Restore original images.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<attachment_ids>]
	 * : Comma-separated list of attachment IDs to restore.
	 *
	 * [--all]
	 * : Restore all optimized files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmo restore --all
	 *     wp fmo restore --ids=12,34
	 *
	 * @param array $args       Command line arguments.
	 * @param array $assoc_args Command line associative arguments.
	 */
	public function restore( $args, $assoc_args ) {
		$restorer = new Restorer();

		if ( isset( $assoc_args['ids'] ) ) {
			$ids = array_map( 'intval', explode( ',', $assoc_args['ids'] ) );
			$success = 0;
			$failed  = 0;

			foreach ( $ids as $id ) {
				$res = $restorer->restore_attachment( $id );
				if ( $res['success'] ) {
					$success++;
				} else {
					$failed++;
				}
			}
			\WP_CLI::success( sprintf( __( 'Restore complete! Success: %1$d, Failed: %2$d', 'ffmpeg-media-optimizer' ), $success, $failed ) );
		} elseif ( isset( $assoc_args['all'] ) ) {
			\WP_CLI::log( __( 'Restoring all optimized images from backups...', 'ffmpeg-media-optimizer' ) );
			$count = $restorer->restore_all();
			Database::reset_all_to_pending();
			\WP_CLI::success( sprintf( __( 'Restore complete! Restored %d attachments.', 'ffmpeg-media-optimizer' ), $count ) );
		} else {
			\WP_CLI::error( __( 'Please specify either --all or --ids=<attachment_ids>.', 'ffmpeg-media-optimizer' ) );
		}
	}

	/**
	 * Clear all failed records so they can be re-optimized.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmo clear-failed
	 *
	 * @param array $args       Command line arguments.
	 * @param array $assoc_args Command line associative arguments.
	 */
	public function clear_failed( $args, $assoc_args ) {
		global $wpdb;
		$table = Database::get_images_table();
		$count = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE status = %s", 'pending', 'failed' ) );

		\WP_CLI::success( sprintf( __( 'Cleared %d failed entries back to pending.', 'ffmpeg-media-optimizer' ), $count ) );
	}

	/**
	 * Run diagnostics testing execution of detected binaries.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmo test-exec
	 *
	 * @param array $args       Command line arguments.
	 * @param array $assoc_args Command line associative arguments.
	 */
	public function test_exec( $args, $assoc_args ) {
		$binaries = Optimizer::detect_binaries();
		\WP_CLI::line( 'Detected Binaries:' );
		foreach ( $binaries as $key => $data ) {
			if ( $data ) {
				\WP_CLI::line( " - {$key}: {$data['path']} (version: {$data['version']})" );
			} else {
				\WP_CLI::line( " - {$key}: NOT DETECTED" );
			}
		}

		foreach ( $binaries as $key => $data ) {
			if ( ! $data ) {
				continue;
			}

			\WP_CLI::line( "\nTesting execution of: {$key}" );

			$flag = ' -version';

			// Test direct command
			$cmd = escapeshellcmd( $data['path'] ) . $flag;
			$res = $this->run_test_cmd( $cmd );
			\WP_CLI::line( " [Direct] Exit code: {$res['code']}" );
			if ( ! empty( $res['stderr'] ) ) {
				\WP_CLI::line( " [Direct] Stderr: " . trim( $res['stderr'] ) );
			}

			// Test nice command if Unix
			if ( strtoupper( substr( PHP_OS, 0, 3 ) ) !== 'WIN' ) {
				$cmd_nice = 'nice -n 19 ' . $cmd;
				$res_nice = $this->run_test_cmd( $cmd_nice );
				\WP_CLI::line( " [Nice] Exit code: {$res_nice['code']}" );
				if ( ! empty( $res_nice['stderr'] ) ) {
					\WP_CLI::line( " [Nice] Stderr: " . trim( $res_nice['stderr'] ) );
				}
			}
		}
	}

	/**
	 * Run helper shell command.
	 */
	private function run_test_cmd( $cmd ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = @proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return array( 'code' => -2, 'stdout' => '', 'stderr' => 'proc_open failed' );
		}
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		return array( 'code' => $code, 'stdout' => $stdout, 'stderr' => $stderr );
	}

	/**
	 * Output report of performance savings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fmo report
	 *
	 * @param array $args       Command line arguments.
	 * @param array $assoc_args Command line associative arguments.
	 */
	public function report( $args, $assoc_args ) {
		global $wpdb;
		$stats = Database::get_dashboard_stats( 'all' );
		$runs  = Database::get_runs_table();

		// Grouped optimizer usages
		$usages = $wpdb->get_results(
			"SELECT optimizer_used, COUNT(id) as count, SUM(saved_bytes) as savings
			FROM {$runs}
			WHERE status = 'success'
			GROUP BY optimizer_used
			ORDER BY count DESC"
		);

		\WP_CLI::line( '==========================================' );
		\WP_CLI::line( __( '         AGENCY PERFORMANCE REPORT', 'ffmpeg-media-optimizer' ) );
		\WP_CLI::line( '==========================================' );
		\WP_CLI::line( sprintf( __( ' Images Optimized:   %d', 'ffmpeg-media-optimizer' ), $stats['optimized'] ) );
		\WP_CLI::line( sprintf( __( ' Failed Attempts:    %d', 'ffmpeg-media-optimizer' ), $stats['failed'] ) );
		\WP_CLI::line( sprintf( __( ' Reclaimed Space:    %s', 'ffmpeg-media-optimizer' ), size_format( $stats['bytes_saved'] ) ) );
		\WP_CLI::line( sprintf( __( ' Duplicate Savings:  %s', 'ffmpeg-media-optimizer' ), size_format( $stats['duplicate_saved'] ) ) );
		\WP_CLI::line( sprintf( __( ' Average Reduction:  %s%%', 'ffmpeg-media-optimizer' ), $stats['average_savings'] ) );
		\WP_CLI::line( '------------------------------------------' );
		\WP_CLI::line( __( ' OPTIMIZER BINARY USAGES:', 'ffmpeg-media-optimizer' ) );
		\WP_CLI::line( '------------------------------------------' );

		if ( ! empty( $usages ) ) {
			foreach ( $usages as $use ) {
				\WP_CLI::line(
					sprintf(
						/* translators: 1: binary name, 2: conversion runs, 3: space saved */
						__( ' - %1$s: %2$d runs | Reclaimed: %3$s', 'ffmpeg-media-optimizer' ),
						strtoupper( $use->optimizer_used ),
						$use->count,
						size_format( $use->savings )
					)
				);
			}
		} else {
			\WP_CLI::line( __( ' No engine optimizations recorded yet.', 'ffmpeg-media-optimizer' ) );
		}
		\WP_CLI::line( '==========================================' );
	}
}
