<?php
/**
 * Admin interface loader.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer\Admin;

use FfmpegMediaOptimizer\Database;
use FfmpegMediaOptimizer\Scanner;
use FfmpegMediaOptimizer\Optimizer;
use FfmpegMediaOptimizer\Restorer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * Configures media library columns, row actions, bulk actions, and exports.
 */
class Admin {

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Media Library column hooks
		add_filter( 'manage_media_columns', array( $this, 'add_media_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_columns' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'add_media_row_actions' ), 10, 2 );

		// Bulk actions in Media Library list view (Feature 13)
		add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_action_notices' ) );

		// Single action AJAX handlers
		add_action( 'wp_ajax_ffmpeg_media_single_optimize', array( $this, 'ajax_single_optimize' ) );
		add_action( 'wp_ajax_ffmpeg_media_single_restore', array( $this, 'ajax_single_restore' ) );
		add_action( 'wp_ajax_ffmpeg_media_single_history', array( $this, 'ajax_single_history' ) );

		// Secure history export handler (Feature 6 & 11)
		add_action( 'admin_init', array( $this, 'handle_history_export' ) );
	}

	/**
	 * Enqueue stylesheet and script files.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_media_library = 'upload' === $screen->id;
		$is_plugin_page   = str_contains( $screen->id, 'ffmpeg-media' );

		if ( ! $is_media_library && ! $is_plugin_page ) {
			return;
		}

		wp_enqueue_style(
			'ffmpeg-media-optimizer-admin',
			FFMPEG_MEDIA_OPTIMIZER_URL . 'assets/admin.css',
			array(),
			FFMPEG_MEDIA_OPTIMIZER_VERSION
		);

		wp_enqueue_script(
			'ffmpeg-media-optimizer-admin',
			FFMPEG_MEDIA_OPTIMIZER_URL . 'assets/admin.js',
			array( 'jquery' ),
			FFMPEG_MEDIA_OPTIMIZER_VERSION,
			true
		);

		wp_localize_script(
			'ffmpeg-media-optimizer-admin',
			'ffmpegMediaSettings',
			array(
				'ajaxurl'  => admin_url( 'admin-ajax.php' ),
				'nonces'   => array(
					'optimize' => wp_create_nonce( 'ffmpeg_media_optimize_nonce' ),
					'restore'  => wp_create_nonce( 'ffmpeg_media_restore_nonce' ),
					'history'  => wp_create_nonce( 'ffmpeg_media_history_nonce' ),
				),
				'messages' => array(
					'optimizing' => __( 'Optimizing...', 'ffmpeg-media-optimizer' ),
					'restoring'  => __( 'Restoring...', 'ffmpeg-media-optimizer' ),
					'error'      => __( 'An error occurred.', 'ffmpeg-media-optimizer' ),
				),
			)
		);
	}

	/**
	 * Add custom columns to Media Library (Feature 13).
	 *
	 * @param array $posts_columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_media_columns( $posts_columns ) {
		$posts_columns['fmo_status']    = __( 'Optimization Status', 'ffmpeg-media-optimizer' );
		$posts_columns['fmo_savings']   = __( 'Savings', 'ffmpeg-media-optimizer' );
		$posts_columns['fmo_optimizer'] = __( 'Optimizer Used', 'ffmpeg-media-optimizer' );
		$posts_columns['fmo_last']      = __( 'Last Optimized', 'ffmpeg-media-optimizer' );
		$posts_columns['fmo_runs']      = __( 'Runs', 'ffmpeg-media-optimizer' );
		return $posts_columns;
	}

	/**
	 * Render custom columns cells.
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Attachment ID.
	 */
	public function render_media_columns( $column_name, $post_id ) {
		$record = Database::get_image_by_attachment( $post_id );

		switch ( $column_name ) {
			case 'fmo_status':
				if ( ! $record ) {
					echo '<span class="ffmpeg-status status-untracked">' . esc_html__( 'Not Scanned', 'ffmpeg-media-optimizer' ) . '</span>';
				} else {
					$status = $record->status;
					printf(
						'<span class="ffmpeg-status status-%1$s">%2$s</span>',
						esc_attr( $status ),
						esc_html( ucfirst( $status ) )
					);
				}
				break;

			case 'fmo_savings':
				if ( $record && 'optimized' === $record->status ) {
					$saved = $record->original_size - $record->current_size;
					$percent = $record->original_size > 0 ? round( ( $saved / $record->original_size ) * 100, 1 ) : 0;
					printf(
						'<strong>%1$s</strong><br/><small>%2$s%% %3$s</small>',
						esc_html( size_format( $saved ) ),
						esc_html( $percent ),
						esc_html__( 'saved', 'ffmpeg-media-optimizer' )
					);
				} else {
					echo '-';
				}
				break;

			case 'fmo_optimizer':
				if ( $record && 'optimized' === $record->status ) {
					$runs = Database::get_runs( $post_id );
					if ( ! empty( $runs ) ) {
						$last = end( $runs );
						echo esc_html( strtoupper( $last->optimizer_used ) );
					} else {
						echo '-';
					}
				} else {
					echo '-';
				}
				break;

			case 'fmo_last':
				if ( $record && ! empty( $record->last_optimized_at ) ) {
					echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record->last_optimized_at ) );
				} else {
					echo '-';
				}
				break;

			case 'fmo_runs':
				if ( $record ) {
					echo esc_html( $record->optimization_count );
				} else {
					echo '0';
				}
				break;
		}
	}

	/**
	 * Append individual row actions (Feature 13).
	 *
	 * @param array    $actions Existing actions.
	 * @param \WP_Post $post    Attachment post object.
	 * @return array Updated actions.
	 */
	public function add_media_row_actions( $actions, $post ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$supported = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' );
		if ( ! in_array( $post->post_mime_type, $supported, true ) ) {
			return $actions;
		}

		$record = Database::get_image_by_attachment( $post->ID );

		// Optimize option
		if ( ! $record || 'optimized' !== $record->status ) {
			$actions['fmo_optimize'] = sprintf(
				'<a href="#" class="ffmpeg-action-optimize" data-id="%d">%s</a>',
				$post->ID,
				__( 'Optimize', 'ffmpeg-media-optimizer' )
			);
		}

		// Restore option
		if ( $record && 'optimized' === $record->status ) {
			$actions['fmo_restore'] = sprintf(
				'<a href="#" class="ffmpeg-action-restore" data-id="%d">%s</a>',
				$post->ID,
				__( 'Restore Original', 'ffmpeg-media-optimizer' )
			);
		}

		// History details option
		if ( $record ) {
			$actions['fmo_history'] = sprintf(
				'<a href="#" class="ffmpeg-action-history" data-id="%d">%s</a>',
				$post->ID,
				__( 'History', 'ffmpeg-media-optimizer' )
			);

			$export_url = wp_nonce_url(
				admin_url( 'admin-ajax.php?action=fmo_export_history&id=' . $post->ID ),
				'fmo_export_history_nonce'
			);
			$actions['fmo_export'] = sprintf(
				'<a href="%s" class="ffmpeg-action-export">%s</a>',
				esc_url( $export_url ),
				__( 'Export History', 'ffmpeg-media-optimizer' )
			);
		}

		return $actions;
	}

	/**
	 * Register bulk actions (Feature 13).
	 */
	public function register_bulk_actions( $bulk_actions ) {
		$bulk_actions['fmo_bulk_optimize'] = __( 'FMO Optimize', 'ffmpeg-media-optimizer' );
		$bulk_actions['fmo_bulk_restore']  = __( 'FMO Restore Original', 'ffmpeg-media-optimizer' );
		return $bulk_actions;
	}

	/**
	 * Handle bulk actions (Feature 13).
	 */
	public function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $redirect_to;
		}

		if ( 'fmo_bulk_optimize' === $action ) {
			$count = 0;
			$scanner = new Scanner();
			foreach ( $post_ids as $id ) {
				if ( $scanner->scan_attachment( $id ) ) {
					$count++;
				}
			}
			$redirect_to = add_query_arg( 'fmo_bulk_opt_queued', $count, $redirect_to );
		}

		if ( 'fmo_bulk_restore' === $action ) {
			$count = 0;
			$restorer = new Restorer();
			foreach ( $post_ids as $id ) {
				$res = $restorer->restore_attachment( $id );
				if ( $res['success'] ) {
					$count++;
				}
			}
			$redirect_to = add_query_arg( 'fmo_bulk_restored', $count, $redirect_to );
		}

		return $redirect_to;
	}

	/**
	 * Bulk actions admin notifications.
	 */
	public function bulk_action_notices() {
		if ( ! empty( $_GET['fmo_bulk_opt_queued'] ) ) {
			$count = (int) $_GET['fmo_bulk_opt_queued'];
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( _n( 'Queued %d image for background optimization.', 'Queued %d images for background optimization.', $count, 'ffmpeg-media-optimizer' ), $count ) )
			);
		}

		if ( ! empty( $_GET['fmo_bulk_restored'] ) ) {
			$count = (int) $_GET['fmo_bulk_restored'];
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( _n( 'Restored %d image to its original file.', 'Restored %d images to their original files.', $count, 'ffmpeg-media-optimizer' ), $count ) )
			);
		}
	}

	/**
	 * AJAX single optimize trigger.
	 */
	public function ajax_single_optimize() {
		check_ajax_referer( 'ffmpeg_media_optimize_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden', 'ffmpeg-media-optimizer' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'error' => __( 'Invalid attachment ID.', 'ffmpeg-media-optimizer' ) ) );
		}

		$scanner = new Scanner();
		$scanner->scan_attachment( $id );

		$optimizer = new Optimizer();
		$result    = $optimizer->optimize_attachment( $id );

		if ( $result['success'] ) {
			ob_start();
			$this->render_media_columns( 'fmo_status', $id );
			$status_html = ob_get_clean();

			ob_start();
			$this->render_media_columns( 'fmo_savings', $id );
			$savings_html = ob_get_clean();

			ob_start();
			$this->render_media_columns( 'fmo_optimizer', $id );
			$opt_html = ob_get_clean();

			ob_start();
			$this->render_media_columns( 'fmo_last', $id );
			$last_html = ob_get_clean();

			ob_start();
			$this->render_media_columns( 'fmo_runs', $id );
			$runs_html = ob_get_clean();

			wp_send_json_success(
				array(
					'status_html'  => $status_html,
					'savings_html' => $savings_html,
					'opt_html'     => $opt_html,
					'last_html'    => $last_html,
					'runs_html'    => $runs_html,
				)
			);
		} else {
			wp_send_json_error( array( 'error' => $result['error'] ) );
		}
	}

	/**
	 * AJAX single restore trigger.
	 */
	public function ajax_single_restore() {
		check_ajax_referer( 'ffmpeg_media_restore_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden', 'ffmpeg-media-optimizer' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'error' => __( 'Invalid attachment ID.', 'ffmpeg-media-optimizer' ) ) );
		}

		$restorer = new Restorer();
		$result   = $restorer->restore_attachment( $id );

		if ( $result['success'] ) {
			ob_start();
			$this->render_media_columns( 'fmo_status', $id );
			$status_html = ob_get_clean();

			ob_start();
			$this->render_media_columns( 'fmo_savings', $id );
			$savings_html = ob_get_clean();

			ob_start();
			$this->render_media_columns( 'fmo_optimizer', $id );
			$opt_html = ob_get_clean();

			ob_start();
			$this->render_media_columns( 'fmo_last', $id );
			$last_html = ob_get_clean();

			ob_start();
			$this->render_media_columns( 'fmo_runs', $id );
			$runs_html = ob_get_clean();

			wp_send_json_success(
				array(
					'status_html'  => $status_html,
					'savings_html' => $savings_html,
					'opt_html'     => $opt_html,
					'last_html'    => $last_html,
					'runs_html'    => $runs_html,
				)
			);
		} else {
			wp_send_json_error( array( 'error' => $result['error'] ) );
		}
	}

	/**
	 * AJAX run history details popup (Feature 6).
	 */
	public function ajax_single_history() {
		check_ajax_referer( 'ffmpeg_media_history_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Forbidden', 'ffmpeg-media-optimizer' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'error' => __( 'Invalid attachment ID.', 'ffmpeg-media-optimizer' ) ) );
		}

		$runs = Database::get_runs( $id );
		if ( empty( $runs ) ) {
			wp_send_json_success( array( 'html' => '<p>' . esc_html__( 'No optimization history found.', 'ffmpeg-media-optimizer' ) . '</p>' ) );
		}

		ob_start();
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Index', 'ffmpeg-media-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Timestamp', 'ffmpeg-media-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Optimizer', 'ffmpeg-media-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Target Quality', 'ffmpeg-media-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Saved Space', 'ffmpeg-media-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'ffmpeg-media-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ffmpeg-media-optimizer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$idx = 1;
				foreach ( $runs as $run ) :
					?>
					<tr>
						<td>Run #<?php echo (int) $idx ++; ?></td>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $run->finished_at ) ); ?></td>
						<td><?php echo esc_html( strtoupper( $run->optimizer_used ) ); ?></td>
						<td><?php echo $run->quality_used > 0 ? (int) $run->quality_used : '-'; ?></td>
						<td>
							<?php
							if ( 'success' === $run->status ) {
								/* translators: 1: bytes saved, 2: percent saved */
								echo esc_html( sprintf( __( '%1$s (%2$s%%)', 'ffmpeg-media-optimizer' ), size_format( $run->saved_bytes ), $run->saved_percent ) );
							} else {
								echo '-';
							}
							?>
						</td>
						<td><?php echo (int) $run->duration; ?>s</td>
						<td>
							<?php if ( 'success' === $run->status ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <?php esc_html_e( 'Success', 'ffmpeg-media-optimizer' ); ?>
							<?php else : ?>
								<span class="dashicons dashicons-warning" style="color: #dc3232;" title="<?php echo esc_attr( $run->error_message ); ?>"></span> <?php esc_html_e( 'Failed', 'ffmpeg-media-optimizer' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Secure history exporter endpoint (Feature 6).
	 */
	public function handle_history_export() {
		if ( ! isset( $_GET['action'] ) || 'fmo_export_history' !== $_GET['action'] ) {
			return;
		}

		check_admin_referer( 'fmo_export_history_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'ffmpeg-media-optimizer' ) );
		}

		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! $id ) {
			wp_die( esc_html__( 'Invalid attachment ID.', 'ffmpeg-media-optimizer' ) );
		}

		$runs = Database::get_runs( $id );
		if ( empty( $runs ) ) {
			wp_die( esc_html__( 'No optimization history available for this attachment.', 'ffmpeg-media-optimizer' ) );
		}

		$filename = 'image-' . $id . '-optimization-history-' . current_time( 'Y-md-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'Run Index', 'Timestamp', 'Optimizer Used', 'Format Used', 'Quality Used', 'Saved Bytes', 'Saved Percent', 'Duration (seconds)', 'Status', 'Error' ) );

		$idx = 1;
		foreach ( $runs as $run ) {
			fputcsv(
				$output,
				array(
					'Run #' . $idx ++,
					$run->finished_at,
					$run->optimizer_used,
					$run->format_used,
					$run->quality_used,
					$run->saved_bytes,
					$run->saved_percent,
					$run->duration,
					$run->status,
					$run->error_message,
				)
			);
		}

		fclose( $output );
		exit;
	}
}
