=== FFmpeg Media Optimizer ===
Contributors: wp-plugin-engineer
Tags: ffmpeg, image optimization, local-optimization, webp, avif
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-hosted image optimization plugin that optimizes WordPress media library images locally using FFmpeg.

== Description ==

FFmpeg Media Optimizer is a self-hosted WordPress plugin designed to compress and optimize images directly on your server using FFmpeg. It does not rely on any third-party APIs or external services, ensuring full privacy and zero recurring subscription fees.

= Key Features =
* **Local Optimization:** Compression is performed entirely on your own server. No data leaves your site.
* **Modern Formats Support:** Easily convert and serve images in next-gen formats like WebP and AVIF.
* **Background Processing:** Optimize images asynchronously in the background via Action Scheduler or WP-Cron without slowing down your frontend.
* **WP-CLI Support:** Run scanning, optimization, and restoration tasks directly from the command line.
* **Lossless/Lossy Optimization:** Configurable compression levels for JPEG, PNG, WebP, and AVIF.
* **Automatic Backups:** Safely keep original uploads and restore them individually or in bulk at any time.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Verify that FFmpeg is detected under **Media -> FFmpeg Optimizer**.
4. Configure your desired quality, format settings, and limits.
5. Go to the dashboard, run a scan, and optimize your media library!

== Frequently Asked Questions ==

= Does this plugin require an API key? =
No. This plugin is 100% self-hosted and processes all images on your local web server.

= What are the requirements? =
Your server must have FFmpeg installed and accessible, and PHP functions like `proc_open` must not be disabled.

== Changelog ==

= 1.0.0 =
* Initial release.
