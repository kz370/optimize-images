<?php
/**
 * Frontend public features and delivery.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Public_Handler
 *
 * Rewrites frontend image outputs to WebP/AVIF based on browser headers.
 */
class Public_Handler {

	/**
	 * Register frontend and rewrite hooks.
	 */
	public function register_hooks() {
		// Apache htaccess rewrite rule filters
		add_filter( 'mod_rewrite_rules', array( $this, 'add_htaccess_rules' ) );

		// Frontend URL rewrite filters
		if ( ! is_admin() ) {
			add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
			add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_image_srcset' ), 10, 5 );
			add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 10, 4 );
		}
	}

	/**
	 * Write rewrite rules for Apache to serve next-gen formats automatically if they exist.
	 *
	 * @param string $rules Existing rewrite rules.
	 * @return string Modified rules.
	 */
	public function add_htaccess_rules( $rules ) {
		$settings = Admin\Settings::get_settings();
		$target   = isset( $settings['conversion_target'] ) ? $settings['conversion_target'] : 'original';

		if ( 'original' === $target ) {
			return $rules;
		}

		$new_rules = "\n# BEGIN FFmpeg Media Optimizer Next-Gen Formats\n";
		$new_rules .= "<IfModule mod_rewrite.c>\n";
		$new_rules .= "  RewriteEngine On\n";
		$new_rules .= "  RewriteBase / \n";

		// AVIF Rule
		if ( 'avif' === $target || 'best' === $target ) {
			$new_rules .= "  RewriteCond %{HTTP_ACCEPT} image/avif\n";
			$new_rules .= "  RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/$1.avif -f\n";
			$new_rules .= "  RewriteRule ^wp-content/uploads/(.+)\.(jpe?g|png|webp)$ wp-content/uploads/$1.avif [T=image/avif,L]\n";
		}

		// WebP Rule
		if ( 'webp' === $target || 'best' === $target ) {
			$new_rules .= "  RewriteCond %{HTTP_ACCEPT} image/webp\n";
			$new_rules .= "  RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/$1.webp -f\n";
			$new_rules .= "  RewriteRule ^wp-content/uploads/(.+)\.(jpe?g|png)$ wp-content/uploads/$1.webp [T=image/webp,L]\n";
		}

		$new_rules .= "</IfModule>\n";
		$new_rules .= "# END FFmpeg Media Optimizer Next-Gen Formats\n\n";

		return $new_rules . $rules;
	}

	/**
	 * Rewrite a single file URL to WebP/AVIF if supported and exists.
	 *
	 * @param string $url Original URL.
	 * @return string Filtered URL.
	 */
	public function filter_url( $url ) {
		if ( empty( $url ) ) {
			return $url;
		}

		$settings = Admin\Settings::get_settings();
		$target   = isset( $settings['conversion_target'] ) ? $settings['conversion_target'] : 'original';

		if ( 'original' === $target ) {
			return $url;
		}

		// Check if the URL is in the uploads directory
		$upload_dir = wp_upload_dir();
		$upload_url = $upload_dir['baseurl'];
		if ( ! str_contains( $url, $upload_url ) ) {
			return $url;
		}

		// Parse file path
		$path     = str_replace( $upload_url, $upload_dir['basedir'], $url );
		$file_path = strtok( $path, '?' ); // Remove query strings

		if ( ! file_exists( $file_path ) ) {
			return $url;
		}

		$file_without_ext = pathinfo( $file_path, PATHINFO_DIRNAME ) . DIRECTORY_SEPARATOR . pathinfo( $file_path, PATHINFO_FILENAME );
		$url_without_ext  = pathinfo( $url, PATHINFO_DIRNAME ) . '/' . pathinfo( $url, PATHINFO_FILENAME );

		// AVIF Check
		if ( ( 'avif' === $target || 'best' === $target ) && $this->browser_supports_avif() ) {
			$avif_file = $file_without_ext . '.avif';
			if ( file_exists( $avif_file ) ) {
				return $url_without_ext . '.avif';
			}
		}

		// WebP Check
		if ( ( 'webp' === $target || 'best' === $target ) && $this->browser_supports_webp() ) {
			$webp_file = $file_without_ext . '.webp';
			if ( file_exists( $webp_file ) ) {
				return $url_without_ext . '.webp';
			}
		}

		return $url;
	}

	/**
	 * Filter wp_get_attachment_url.
	 */
	public function filter_attachment_url( $url, $post_id ) {
		return $this->filter_url( $url );
	}

	/**
	 * Filter wp_get_attachment_image_src.
	 */
	public function filter_image_src( $image, $attachment_id, $size, $icon ) {
		if ( ! empty( $image[0] ) ) {
			$image[0] = $this->filter_url( $image[0] );
		}
		return $image;
	}

	/**
	 * Filter wp_calculate_image_srcset.
	 */
	public function filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( empty( $sources ) || ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source_info ) {
			if ( ! empty( $source_info['url'] ) ) {
				$sources[ $width ]['url'] = $this->filter_url( $source_info['url'] );
			}
		}

		return $sources;
	}

	/**
	 * Verify if user browser accepts WebP.
	 */
	private function browser_supports_webp() {
		return ( isset( $_SERVER['HTTP_ACCEPT'] ) && str_contains( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) );
	}

	/**
	 * Verify if user browser accepts AVIF.
	 */
	private function browser_supports_avif() {
		return ( isset( $_SERVER['HTTP_ACCEPT'] ) && str_contains( $_SERVER['HTTP_ACCEPT'], 'image/avif' ) );
	}
}
