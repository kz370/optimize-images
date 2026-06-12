<?php
/**
 * Plugin database class.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Database
 *
 * Handles database operations: schema creation, updates, and custom queries.
 */
class Database {

	/**
	 * Get the images table name.
	 *
	 * @return string Table name.
	 */
	public static function get_images_table() {
		global $wpdb;
		return $wpdb->prefix . 'ffmpeg_media_optimizer_images';
	}

	/**
	 * Get the optimization runs table name.
	 *
	 * @return string Table name.
	 */
	public static function get_runs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ffmpeg_media_optimization_runs';
	}

	/**
	 * Initialize DB class.
	 */
	public static function init() {
		// Place any runtime initialization here if needed.
	}

	/**
	 * Create or update database tables.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_images = self::get_images_table();
		$table_runs   = self::get_runs_table();

		// WP CS requires 2 spaces after PRIMARY KEY and KEY inside dbDelta
		$sql_images = "CREATE TABLE {$table_images} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			file_path varchar(255) NOT NULL,
			original_size bigint(20) unsigned NOT NULL,
			current_size bigint(20) unsigned NOT NULL,
			optimization_count int(11) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL,
			hash varchar(32) NOT NULL,
			last_optimized_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY status (status),
			KEY hash (hash)
		) {$charset_collate};";

		$sql_runs = "CREATE TABLE {$table_runs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			optimizer_used varchar(50) NOT NULL,
			format_used varchar(10) NOT NULL,
			quality_used int(5) NOT NULL,
			saved_bytes bigint(20) NOT NULL,
			saved_percent decimal(5,2) NOT NULL,
			status varchar(20) NOT NULL,
			error_message text DEFAULT NULL,
			duration int(11) unsigned NOT NULL DEFAULT 0,
			started_at datetime NOT NULL,
			finished_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql_images );
		dbDelta( $sql_runs );
	}

	/**
	 * Insert or update attachment status record.
	 *
	 * @param array $data Row data.
	 * @return int|bool Row ID or false.
	 */
	public static function save_image_status( $data ) {
		global $wpdb;
		$table = self::get_images_table();

		if ( empty( $data['attachment_id'] ) ) {
			return false;
		}

		$existing = self::get_image_by_attachment( $data['attachment_id'] );
		$now      = current_time( 'mysql', 1 );

		if ( $existing ) {
			$data['updated_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$table,
				$data,
				array( 'attachment_id' => $data['attachment_id'] )
			);
			return $result !== false ? $existing->id : false;
		} else {
			$data['created_at'] = $now;
			$data['updated_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->insert( $table, $data );
			return $result ? $wpdb->insert_id : false;
		}
	}

	/**
	 * Retrieve a single image record by attachment ID.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return object|null Row object or null.
	 */
	public static function get_image_by_attachment( $attachment_id ) {
		global $wpdb;
		$table = self::get_images_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE attachment_id = %d LIMIT 1", $table, $attachment_id )
		);
	}

	/**
	 * Retrieve a list of image records by attachment IDs.
	 *
	 * @param array $attachment_ids Attachment IDs.
	 * @return array Map of attachment_id => record object.
	 */
	public static function get_images_by_attachments( $attachment_ids ) {
		global $wpdb;
		if ( empty( $attachment_ids ) ) {
			return array();
		}

		$table = self::get_images_table();
		$ids   = array_map( 'intval', $attachment_ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE attachment_id IN ($placeholders)",
				array_merge( array( $table ), $ids )
			)
		);

		$map = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ $row->attachment_id ] = $row;
			}
		}

		return $map;
	}

	/**
	 * Log an optimization run attempt.
	 *
	 * @param array $data Run details.
	 * @return int|bool Run ID or false.
	 */
	public static function log_run( $data ) {
		global $wpdb;
		$table = self::get_runs_table();

		$data['started_at']  = ! empty( $data['started_at'] ) ? $data['started_at'] : current_time( 'mysql', 1 );
		$data['finished_at'] = ! empty( $data['finished_at'] ) ? $data['finished_at'] : current_time( 'mysql', 1 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $table, $data );
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Get optimization history for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Array of run objects.
	 */
	public static function get_runs( $attachment_id ) {
		global $wpdb;
		$table = self::get_runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE attachment_id = %d ORDER BY id ASC", $table, $attachment_id )
		);
	}

	/**
	 * Get WooCommerce attachment IDs based on scope.
	 *
	 * @param string $scope Scope name ('wc', 'products', 'galleries', 'categories').
	 * @return array List of IDs.
	 */
	public static function get_wc_attachment_ids( $scope = 'wc' ) {
		global $wpdb;
		$ids = array();

		switch ( $scope ) {
			case 'products':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col(
					"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} pm
					JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					WHERE pm.meta_key = '_thumbnail_id'
					AND p.post_type IN ('product', 'product_variation')
					AND pm.meta_value > 0"
				);
				break;

			case 'galleries':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$galleries = $wpdb->get_col(
					"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} pm
					JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					WHERE pm.meta_key = '_product_image_gallery'
					AND p.post_type = 'product'
					AND pm.meta_value != ''"
				);
				if ( ! empty( $galleries ) ) {
					foreach ( $galleries as $gallery ) {
						$gallery_ids = explode( ',', $gallery );
						foreach ( $gallery_ids as $gid ) {
							$gid = (int) trim( $gid );
							if ( $gid > 0 ) {
								$ids[] = $gid;
							}
						}
					}
					$ids = array_unique( $ids );
				}
				break;

			case 'categories':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col(
					"SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
					WHERE meta_key = 'thumbnail_id'
					AND meta_value > 0"
				);
				break;

			case 'wc':
			default:
				$prod_ids = self::get_wc_attachment_ids( 'products' );
				$gal_ids  = self::get_wc_attachment_ids( 'galleries' );
				$cat_ids  = self::get_wc_attachment_ids( 'categories' );
				$ids      = array_unique( array_merge( $prod_ids, $gal_ids, $cat_ids ) );
				break;
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Retrieve dashboard stats.
	 *
	 * @param string $scope Optimization scope ('all' or WC scopes).
	 * @return array Statistics data.
	 */
	public static function get_dashboard_stats( $scope = 'all' ) {
		global $wpdb;
		$table_images = self::get_images_table();

		// Fetch target ids if WooCommerce scoped
		$wc_ids = array();
		$is_wc_scope = in_array( $scope, array( 'wc', 'products', 'galleries', 'categories' ), true );
		if ( $is_wc_scope ) {
			$wc_ids = self::get_wc_attachment_ids( $scope );
		}

		if ( $is_wc_scope && empty( $wc_ids ) ) {
			return array(
				'total'             => 0,
				'pending'           => 0,
				'optimized'         => 0,
				'failed'            => 0,
				'original_size'     => 0,
				'current_size'      => 0,
				'bytes_saved'       => 0,
				'duplicate_saved'   => 0,
				'duplicate_count'   => 0,
				'average_savings'   => 0,
			);
		}

		// Base query parts
		$posts_where = "p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif')";
		$images_where = "1=1";

		if ( $is_wc_scope ) {
			$in_list      = implode( ',', $wc_ids );
			$posts_where  .= " AND p.ID IN ({$in_list})";
			$images_where .= " AND attachment_id IN ({$in_list})";
		}

		// Total attachments compatible
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var( "SELECT COUNT(p.ID) FROM {$wpdb->posts} p WHERE {$posts_where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// DB counts
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pending = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE status = 'pending' AND {$images_where}", $table_images ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$optimized = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE status = 'optimized' AND {$images_where}", $table_images ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$failed = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM %i WHERE status = 'failed' AND {$images_where}", $table_images ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Sizes
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$savings = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(original_size) as orig, SUM(current_size) as curr FROM %i WHERE status = 'optimized' AND {$images_where}", $table_images ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$original_size = $savings ? (int) $savings->orig : 0;
		$current_size  = $savings ? (int) $savings->curr : 0;
		$bytes_saved   = max( 0, $original_size - $current_size );

		// Duplicate stats
		$dup_where = $images_where . " AND optimizer_used = 'duplicate-reuse'";
		$table_runs = self::get_runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$dup_stats = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(id) as cnt, SUM(saved_bytes) as bytes FROM %i WHERE status = 'success' AND {$dup_where}", $table_runs ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$duplicate_count = $dup_stats ? (int) $dup_stats->cnt : 0;
		$duplicate_saved = $dup_stats ? (int) $dup_stats->bytes : 0;

		$average_savings = 0;
		if ( $original_size > 0 ) {
			$average_savings = round( ( $bytes_saved / $original_size ) * 100, 2 );
		}

		return array(
			'total'             => (int) $total,
			'pending'           => (int) $pending,
			'optimized'         => (int) $optimized,
			'failed'            => (int) $failed,
			'original_size'     => $original_size,
			'current_size'      => $current_size,
			'bytes_saved'       => $bytes_saved,
			'duplicate_saved'   => $duplicate_saved,
			'duplicate_count'   => $duplicate_count,
			'average_savings'   => $average_savings,
		);
	}

	/**
	 * Get pending image attachment IDs.
	 *
	 * @param int    $limit Max size of batch.
	 * @param string $scope Scope query ('all', 'wc', 'products', 'galleries', 'categories').
	 * @return array List of attachment IDs.
	 */
	public static function get_pending_ids( $limit = 20, $scope = 'all' ) {
		global $wpdb;
		$table = self::get_images_table();

		$wc_ids = array();
		$is_wc_scope = in_array( $scope, array( 'wc', 'products', 'galleries', 'categories' ), true );
		if ( $is_wc_scope ) {
			$wc_ids = self::get_wc_attachment_ids( $scope );
			if ( empty( $wc_ids ) ) {
				return array();
			}
		}

		if ( $is_wc_scope ) {
			$placeholders = implode( ',', array_fill( 0, count( $wc_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT attachment_id FROM %i WHERE status = %s AND attachment_id IN ($placeholders) ORDER BY id ASC LIMIT %d",
					array_merge( array( $table, 'pending' ), array_map( 'intval', $wc_ids ), array( $limit ) )
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_col(
				$wpdb->prepare(
					"SELECT attachment_id FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d",
					$table,
					'pending',
					$limit
				)
			);
		}
	}

	/**
	 * Get daily savings for the charts.
	 */
	public static function get_daily_savings( $days = 30 ) {
		global $wpdb;
		$table = self::get_runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(finished_at) as date_day, SUM(saved_bytes) as bytes
				FROM %i
				WHERE status = 'success'
				AND finished_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				GROUP BY DATE(finished_at)
				ORDER BY DATE(finished_at) ASC",
				$table,
				$days
			)
		);
	}

	/**
	 * Get monthly savings for the charts.
	 */
	public static function get_monthly_savings( $months = 12 ) {
		global $wpdb;
		$table = self::get_runs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(finished_at, '%%Y-%%m') as date_month, SUM(saved_bytes) as bytes
				FROM %i
				WHERE status = 'success'
				AND finished_at >= DATE_SUB(NOW(), INTERVAL %d MONTH)
				GROUP BY DATE_FORMAT(finished_at, '%%Y-%%m')
				ORDER BY DATE_FORMAT(finished_at, '%%Y-%%m') ASC",
				$table,
				$months
			)
		);
	}

	/**
	 * Reset stats and set all records to pending.
	 */
	public static function reset_all_to_pending() {
		global $wpdb;
		$table = self::get_images_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = %s", $table, 'pending' ) );
	}

	/**
	 * Retrieve a single image record by its file path.
	 *
	 * @param string $file_path Absolute file path.
	 * @return object|null Row object or null.
	 */
	public static function get_image_by_path( $file_path ) {
		global $wpdb;
		$table = self::get_images_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE file_path = %s LIMIT 1", $table, $file_path )
		);
	}
}
