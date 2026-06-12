<?php
/**
 * Main plugin class.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Coordinates the main hooks and bootstraps the components.
 */
class Plugin {

	/**
	 * Run the plugin.
	 */
	public function run() {
		// Load translations.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Initialize Database.
		Database::init();

		// Initialize Logger.
		Logger::init();

		// Register health check triggers.
		Health_Check::register_hooks();

		// Initialize Background Processor.
		$background_processor = new Background_Processor();
		$background_processor->register_hooks();

		// Initialize Admin features.
		if ( is_admin() ) {
			$admin = new Admin\Admin();
			$admin->register_hooks();

			$settings = new Admin\Settings();
			$settings->register_hooks();

			$bulk_page = new Admin\Bulk_Page();
			$bulk_page->register_hooks();
		}

		// Initialize Public features.
		$public_handler = new Public_Handler();
		$public_handler->register_hooks();

		// Initialize WP-CLI commands.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register_commands();
		}

		// Media Automation hooks.
		add_action( 'add_attachment', array( $this, 'handle_new_attachment' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle_attachment_metadata' ), 10, 2 );
	}

	/**
	 * Load translation files.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'ffmpeg-media-optimizer',
			false,
			dirname( FFMPEG_MEDIA_OPTIMIZER_BASENAME ) . '/languages/'
		);
	}

	/**
	 * Hook when an attachment is newly uploaded.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function handle_new_attachment( $attachment_id ) {
		$settings = Admin\Settings::get_settings();
		if ( ! empty( $settings['optimize_on_upload'] ) ) {
			// Scan the single attachment.
			$scanner = new Scanner();
			$scanner->scan_attachment( $attachment_id );

			// Queue it for processing.
			$background = new Background_Processor();
			$background->enqueue_attachment( $attachment_id );
		}
	}

	/**
	 * Hook when attachment metadata is generated or regenerated.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function handle_attachment_metadata( $metadata, $attachment_id ) {
		$settings = Admin\Settings::get_settings();

		// Determine if optimization is triggered on metadata generation.
		// WP generates/regenerates attachment metadata on upload and thumbnail regeneration.
		$is_upload     = current_filter() === 'wp_generate_attachment_metadata' && ! empty( $settings['optimize_on_upload'] );
		$is_regenerate = ! empty( $settings['optimize_regenerated'] );

		if ( $is_upload || $is_regenerate ) {
			// Scan the attachment to make sure we track all sub-sizes correctly.
			$scanner = new Scanner();
			$scanner->scan_attachment( $attachment_id );

			// Queue it.
			$background = new Background_Processor();
			$background->enqueue_attachment( $attachment_id );
		}

		return $metadata;
	}
}
