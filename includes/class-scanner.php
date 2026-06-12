<?php
/**
 * Media library scanner.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Scanner
 *
 * Handles library scanning in batches, avoiding memory exhaustion.
 */
class Scanner {

	/**
	 * Scan a single attachment and add/update its database entry.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True if successfully scanned.
	 */
	public function scan_attachment( $attachment_id ) {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}

		$mime = get_post_mime_type( $post );
		$supported = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );
		if ( ! in_array( $mime, $supported, true ) ) {
			return false;
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		$size = filesize( $file_path );
		$hash = md5_file( $file_path );

		Database::save_image_status(
			array(
				'attachment_id'      => $attachment_id,
				'file_path'          => $file_path,
				'original_size'      => $size,
				'current_size'       => $size,
				'optimization_count' => 0,
				'status'             => 'pending',
				'hash'               => $hash,
			)
		);

		return true;
	}

	/**
	 * Run a batch scan of the media library.
	 *
	 * @param int $offset Starting offset.
	 * @param int $limit  Batch size.
	 * @return int Number of new/updated attachments scanned in this batch.
	 */
	public function scan_batch( $offset = 0, $limit = 500 ) {
		global $wpdb;

		// Query attachment IDs of supported mime types.
		// Directly querying DB is faster for large media libraries.
		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/avif')
			ORDER BY ID DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$attachment_ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $attachment_ids ) ) {
			return 0;
		}

		// Batch fetch existing statuses to avoid N+1 queries.
		$existing_records = Database::get_images_by_attachments( $attachment_ids );
		$scanned_count    = 0;

		foreach ( $attachment_ids as $id ) {
			$file_path = get_attached_file( $id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				continue;
			}

			$size = filesize( $file_path );
			$hash = md5_file( $file_path );

			$exists = isset( $existing_records[ $id ] ) ? $existing_records[ $id ] : null;

			// If it doesn't exist, or file size/hash changed (e.g. regenerated/replaced), update
			if ( ! $exists || $exists->hash !== $hash ) {
				Database::save_image_status(
					array(
						'attachment_id'      => (int) $id,
						'file_path'          => $file_path,
						'original_size'      => $size,
						'current_size'       => $size,
						'optimization_count' => $exists ? (int) $exists->optimization_count : 0,
						'status'             => 'pending',
						'hash'               => $hash,
					)
				);
				$scanned_count ++;
			}
		}

		return $scanned_count;
	}

	/**
	 * Get count of unscanned/untracked image attachments in Media Library.
	 *
	 * @return int Count.
	 */
	public function get_untracked_count() {
		global $wpdb;
		$table = Database::get_images_table();

		// Count items in posts that are NOT in our images table
		$query = "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			LEFT JOIN {$table} i ON p.ID = i.attachment_id
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/avif')
			AND i.attachment_id IS NULL";

		return (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
