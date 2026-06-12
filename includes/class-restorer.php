<?php
/**
 * Restoration manager.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Restorer
 *
 * Reverts optimized images back to their pre-optimized originals.
 */
class Restorer {

	/**
	 * Restore a single attachment to its original file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Result state.
	 */
	public function restore_attachment( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path ) {
			return array(
				'success' => false,
				'error'   => __( 'Attachment file path not found.', 'optimize-images' ),
			);
		}

		$upload_dir = wp_upload_dir();
		$basedir    = $upload_dir['basedir'];

		// Verify backup file exists
		$relative = str_replace( $basedir, '', $file_path );
		$backup   = $basedir . '/.optimize-images-backups' . $relative;

		if ( ! file_exists( $backup ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Original backup file does not exist.', 'optimize-images' ),
			);
		}

		// List of files to restore
		$files_to_restore = array(
			'original' => array(
				'backup' => $backup,
				'target' => $file_path,
			),
		);

		// Scan metadata to locate thumbnails
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$dirname  = dirname( $file_path );

		if ( ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size => $size_info ) {
				if ( ! empty( $size_info['file'] ) ) {
					$thumb_target = $dirname . DIRECTORY_SEPARATOR . $size_info['file'];
					$thumb_relative = str_replace( $basedir, '', $thumb_target );
					$thumb_backup = $basedir . '/.optimize-images-backups' . $thumb_relative;

					if ( file_exists( $thumb_backup ) ) {
						$files_to_restore[ $size ] = array(
							'backup' => $thumb_backup,
							'target' => $thumb_target,
						);
					}
				}
			}
		}

		$restored_count = 0;
		foreach ( $files_to_restore as $key => $paths ) {
			// Copy backup back over the optimized file
			if ( copy( $paths['backup'], $paths['target'] ) ) {
				// Remove the backup file to clean up storage
				wp_delete_file( $paths['backup'] );
				$restored_count++;
			}

			// Clean up any generated next-gen WebP/AVIF versions
			$target_without_ext = pathinfo( $paths['target'], PATHINFO_DIRNAME ) . DIRECTORY_SEPARATOR . pathinfo( $paths['target'], PATHINFO_FILENAME );
			$webp_version       = $target_without_ext . '.webp';
			$avif_version       = $target_without_ext . '.avif';

			if ( file_exists( $webp_version ) ) {
				wp_delete_file( $webp_version );
			}
			if ( file_exists( $avif_version ) ) {
				wp_delete_file( $avif_version );
			}
		}

		if ( $restored_count > 0 ) {
			// Update Database state.
			// Revert sizes back to original size.
			$original_size = filesize( $file_path );
			Database::save_image_status(
				array(
					'attachment_id' => $attachment_id,
					'current_size'  => $original_size,
					'status'        => 'restored',
				)
			);

			Logger::log( sprintf( 'Restored attachment ID %d from original backups.', $attachment_id ), 'info' );

			return array( 'success' => true );
		}

		return array(
			'success' => false,
			'error'   => __( 'Failed to restore original files.', 'optimize-images' ),
		);
	}

	/**
	 * Restore all optimized files in the database back to their original state.
	 *
	 * @return int Number of attachments successfully restored.
	 */
	public function restore_all() {
		global $wpdb;
		$table = Database::get_images_table();

		// Fetch all optimized attachments.
		$query = $wpdb->prepare( "SELECT attachment_id FROM {$table} WHERE status = %s", 'optimized' );
		$ids   = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $ids ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $ids as $id ) {
			$result = $this->restore_attachment( $id );
			if ( $result['success'] ) {
				$count++;
			}
		}

		return $count;
	}
}
