<?php
/**
 * Bulk optimization page view template.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer\Admin;

use FfmpegMediaOptimizer\Optimizer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nonce    = wp_create_nonce( 'ffmpeg_media_bulk_nonce' );
$binaries = Optimizer::detect_binaries();
$wc_active = class_exists( 'WooCommerce' );
?>
<div class="wrap ffmpeg-bulk-wrap" data-nonce="<?php echo esc_attr( $nonce ); ?>">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<!-- Crash Recovery Warning Box (Feature 4) -->
	<div id="fmo-recovery-warning" class="notice notice-warning" style="display:none; padding: 15px; border-left-color: #dc3232; margin-top: 15px;">
		<h2 style="margin: 0 0 10px 0; color: #d63638; font-size: 16px; font-weight:600;">
			<span class="dashicons dashicons-warning" style="vertical-align: middle; margin-right: 5px;"></span>
			<?php esc_html_e( 'Optimization interrupted.', 'ffmpeg-media-optimizer' ); ?>
		</h2>
		<p style="margin: 0 0 15px 0; font-size: 13px;">
			<?php esc_html_e( 'The background image queue has stalled unexpectedly (possibly due to a server reboot, PHP fatal error, or WP-Cron interruption). Would you like to resume from your previous position or clear the queue?', 'ffmpeg-media-optimizer' ); ?>
		</p>
		<div class="fmo-recovery-buttons">
			<button type="button" class="button button-primary" id="btn-recovery-resume"><?php esc_html_e( 'Resume Queue', 'ffmpeg-media-optimizer' ); ?></button>
			<button type="button" class="button button-secondary" id="btn-recovery-restart" style="margin-left:5px;"><?php esc_html_e( 'Restart Queue', 'ffmpeg-media-optimizer' ); ?></button>
			<button type="button" class="button button-link-delete" id="btn-recovery-clear" style="margin-left:15px; vertical-align: middle;"><?php esc_html_e( 'Clear Queue', 'ffmpeg-media-optimizer' ); ?></button>
		</div>
	</div>

	<!-- Real Savings Dashboard Widgets Grid (Feature 3) -->
	<div class="ffmpeg-dashboard-grid" style="margin-top: 20px;">
		<div class="ffmpeg-card card-total">
			<div class="card-inner">
				<h3><?php esc_html_e( 'Total Images', 'ffmpeg-media-optimizer' ); ?></h3>
				<span class="stat-number" id="stat-total">-</span>
				<p class="stat-desc"><?php esc_html_e( 'Compatible images in scope.', 'ffmpeg-media-optimizer' ); ?></p>
			</div>
		</div>
		<div class="ffmpeg-card card-optimized">
			<div class="card-inner">
				<h3><?php esc_html_e( 'Images Optimized', 'ffmpeg-media-optimizer' ); ?></h3>
				<span class="stat-number" id="stat-optimized">-</span>
				<p class="stat-desc"><?php esc_html_e( 'Images successfully compressed.', 'ffmpeg-media-optimizer' ); ?></p>
			</div>
		</div>
		<div class="ffmpeg-card card-pending">
			<div class="card-inner">
				<h3><?php esc_html_e( 'Queue Status', 'ffmpeg-media-optimizer' ); ?></h3>
				<span class="stat-number" id="stat-pending">-</span>
				<p class="stat-desc" id="queue-status-desc"><?php esc_html_e( 'Images waiting in queue.', 'ffmpeg-media-optimizer' ); ?></p>
			</div>
		</div>
		<div class="ffmpeg-card card-failed">
			<div class="card-inner">
				<h3><?php esc_html_e( 'Failed Jobs', 'ffmpeg-media-optimizer' ); ?></h3>
				<span class="stat-number" id="stat-failed">-</span>
				<p class="stat-desc"><?php esc_html_e( 'Conversion errors logged.', 'ffmpeg-media-optimizer' ); ?></p>
			</div>
		</div>
		<div class="ffmpeg-card card-savings">
			<div class="card-inner">
				<h3><?php esc_html_e( 'Space Saved', 'ffmpeg-media-optimizer' ); ?></h3>
				<span class="stat-number" id="stat-savings">-</span>
				<p class="stat-desc" id="duplicate-desc"><?php esc_html_e( 'Total reclaimed storage.', 'ffmpeg-media-optimizer' ); ?></p>
			</div>
		</div>
		<div class="ffmpeg-card card-avg">
			<div class="card-inner">
				<h3><?php esc_html_e( 'Average Savings', 'ffmpeg-media-optimizer' ); ?></h3>
				<span class="stat-number" id="stat-avg">-</span>
				<p class="stat-desc"><?php esc_html_e( 'Average reduction ratio.', 'ffmpeg-media-optimizer' ); ?></p>
			</div>
		</div>
	</div>

	<!-- Dashboard Secondary Grid (Feature 1 & Feature 3) -->
	<div class="ffmpeg-dashboard-secondary-grid" style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px;">
		<!-- Detected Binary Optimizers (Feature 1) -->
		<div class="fmo-dashboard-box" style="flex: 1 1 300px; background:#fff; border:1px solid #e5e5e5; border-radius:6px; padding:20px;">
			<h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">
				<span class="dashicons dashicons-admin-plugins" style="vertical-align: middle; margin-right: 5px;"></span>
				<?php esc_html_e( 'Detected Optimizers', 'ffmpeg-media-optimizer' ); ?>
			</h2>
			<ul class="fmo-binary-list" style="margin: 0; padding: 0; list-style: none;">
				<?php
				$check_keys = array(
					'ffmpeg'    => 'FFmpeg',
				);
				foreach ( $check_keys as $key => $label ) :
					$exists = ! empty( $binaries[ $key ] );
					$icon   = $exists ? '<span style="color: #46b450; font-weight: bold; margin-right: 8px;">✓</span>' : '<span style="color: #d63638; font-weight: bold; margin-right: 8px;">✗</span>';
					$version = $exists ? ' (' . esc_html( $binaries[ $key ]['version'] ) . ')' : '';
					?>
					<li style="padding: 10px 0; border-bottom: 1px solid #f6f7f7; font-size: 13px;">
						<?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<strong><?php echo esc_html( $label ); ?></strong>
						<span style="color: #8c8f94; float: right; font-family: monospace;"><?php echo $exists ? esc_html__( 'Detected', 'ffmpeg-media-optimizer' ) . $version : esc_html__( 'Not Found', 'ffmpeg-media-optimizer' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<!-- WooCommerce Optimization scope widget (Feature 5) -->
		<div class="fmo-dashboard-box" style="flex: 1 1 400px; background:#fff; border:1px solid #e5e5e5; border-radius:6px; padding:20px;">
			<h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">
				<span class="dashicons dashicons-cart" style="vertical-align: middle; margin-right: 5px;"></span>
				<?php esc_html_e( 'WooCommerce Target Scopes', 'ffmpeg-media-optimizer' ); ?>
			</h2>
			<?php if ( $wc_active ) : ?>
				<div style="margin-bottom: 20px;">
					<label for="fmo-scope-select" style="font-weight: 600; display: block; margin-bottom: 8px;"><?php esc_html_e( 'Select Active Scope:', 'ffmpeg-media-optimizer' ); ?></label>
					<select id="fmo-scope-select" style="width: 100%; max-width: 300px; height: 35px;">
						<option value="all"><?php esc_html_e( 'Entire Library', 'ffmpeg-media-optimizer' ); ?></option>
						<option value="wc"><?php esc_html_e( 'WooCommerce Only (All Categories & Products)', 'ffmpeg-media-optimizer' ); ?></option>
						<option value="products"><?php esc_html_e( 'Product Featured Images Only', 'ffmpeg-media-optimizer' ); ?></option>
						<option value="galleries"><?php esc_html_e( 'Product Galleries Only', 'ffmpeg-media-optimizer' ); ?></option>
						<option value="categories"><?php esc_html_e( 'Category Images Only', 'ffmpeg-media-optimizer' ); ?></option>
					</select>
				</div>
				<div class="wc-savings-board" style="background: #f6f7f7; padding: 15px; border-radius: 4px; border-left: 4px solid #dba617;">
					<h4 style="margin: 0 0 10px 0; font-size: 13px; text-transform: uppercase; color: #646970;"><?php esc_html_e( 'WooCommerce Space Saved', 'ffmpeg-media-optimizer' ); ?></h4>
					<span id="stat-wc-savings" style="font-size: 24px; font-weight: 700; color: #1d2327;">0 KB</span>
				</div>
			<?php else : ?>
				<p style="color:#8c8f94; font-style: italic; margin-top:20px;">
					<?php esc_html_e( 'WooCommerce optimization scope is unavailable because the plugin is not active.', 'ffmpeg-media-optimizer' ); ?>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<!-- Queue Management control console (Feature 14) -->
	<div class="ffmpeg-control-panel" style="margin-bottom: 30px;">
		<h2><?php esc_html_e( 'Queue Management', 'ffmpeg-media-optimizer' ); ?></h2>
		
		<div class="ffmpeg-buttons-row">
			<button type="button" class="button button-secondary button-hero" id="btn-scan">
				<span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Scan Library', 'ffmpeg-media-optimizer' ); ?>
			</button>
			<button type="button" class="button button-primary button-hero" id="btn-optimize" disabled>
				<span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Optimize All', 'ffmpeg-media-optimizer' ); ?>
			</button>
			<button type="button" class="button button-secondary button-hero" id="btn-pause" style="display:none;">
				<span class="dashicons dashicons-controls-pause"></span> <?php esc_html_e( 'Pause', 'ffmpeg-media-optimizer' ); ?>
			</button>
			<button type="button" class="button button-primary button-hero" id="btn-resume" style="display:none;">
				<span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Resume', 'ffmpeg-media-optimizer' ); ?>
			</button>
			<button type="button" class="button button-link-delete button-hero" id="btn-stop" style="display:none; margin-left:10px;">
				<span class="dashicons dashicons-controls-x"></span> <?php esc_html_e( 'Cancel Queue', 'ffmpeg-media-optimizer' ); ?>
			</button>
			<button type="button" class="button button-secondary button-hero" id="btn-retry-failed" style="margin-left: 10px; display:none;">
				<span class="dashicons dashicons-reload"></span> <?php esc_html_e( 'Retry Failed', 'ffmpeg-media-optimizer' ); ?>
			</button>
			<button type="button" class="button button-secondary button-hero" id="btn-restore-all" style="float: right;">
				<span class="dashicons dashicons-undo"></span> <?php esc_html_e( 'Restore All', 'ffmpeg-media-optimizer' ); ?>
			</button>
		</div>

		<!-- Progress Bar -->
		<div class="ffmpeg-progress-wrapper" style="display:none; margin-top:20px;">
			<div class="ffmpeg-progress-label">
				<span id="progress-text"><?php esc_html_e( 'Processing queue...', 'ffmpeg-media-optimizer' ); ?></span>
				<span id="progress-percent">0%</span>
			</div>
			<div class="ffmpeg-progress-bar-container">
				<div class="ffmpeg-progress-bar-fill" style="width: 0%;"></div>
			</div>
			<div id="progress-current-file-details" style="margin-top: 8px; font-size: 13px; color: #646970; display: none;">
				<strong><?php esc_html_e( 'Current file:', 'ffmpeg-media-optimizer' ); ?></strong> <span id="progress-current-filename"></span> 
				(<span id="progress-current-sizes"></span>)
			</div>
		</div>
	</div>

	<!-- SVG Interactive Timeline Charts (Feature 3) -->
	<div class="ffmpeg-charts-panel" style="background:#fff; border:1px solid #e5e5e5; border-radius:6px; padding:25px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
		<h2><?php esc_html_e( 'Savings Timeline Charts', 'ffmpeg-media-optimizer' ); ?></h2>
		<div style="margin-bottom: 20px;">
			<button type="button" class="button button-secondary fmo-chart-tab active" data-chart="daily"><?php esc_html_e( 'Last 30 Days (Daily)', 'ffmpeg-media-optimizer' ); ?></button>
			<button type="button" class="button button-secondary fmo-chart-tab" data-chart="monthly" style="margin-left: 5px;"><?php esc_html_e( 'Last 12 Months (Monthly)', 'ffmpeg-media-optimizer' ); ?></button>
		</div>
		<div id="fmo-svg-chart-container" style="width: 100%; height: 300px; background: #fafafa; border: 1px solid #eee; border-radius:4px; padding: 15px; box-sizing: border-box;">
			<!-- SVG chart will be drawn dynamically inside this div -->
		</div>
	</div>

	<!-- Secure Agency Exporter Form (Feature 11) -->
	<div class="ffmpeg-exporter-panel" style="background:#fff; border:1px solid #e5e5e5; border-radius:6px; padding:25px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
		<h2><?php esc_html_e( 'Agency Report Exporter', 'ffmpeg-media-optimizer' ); ?></h2>
		<p class="description" style="margin-bottom: 20px;"><?php esc_html_e( 'Generate and download secure reports including image parameters, compression summaries and engine metrics.', 'ffmpeg-media-optimizer' ); ?></p>
		
		<?php
		$export_nonce = wp_create_nonce( 'fmo_agency_report_nonce' );
		$export_url   = admin_url( 'admin-ajax.php?action=fmo_agency_report&nonce=' . $export_nonce );
		?>
		<form id="fmo-exporter-form" method="get" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" target="_blank" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
			<input type="hidden" name="action" value="fmo_agency_report" />
			<input type="hidden" name="nonce" value="<?php echo esc_attr( $export_nonce ); ?>" />

			<div>
				<label style="font-weight:600; display:block; margin-bottom: 5px;"><?php esc_html_e( 'Start Date:', 'ffmpeg-media-optimizer' ); ?></label>
				<input type="date" name="start_date" style="height: 30px;" />
			</div>
			<div>
				<label style="font-weight:600; display:block; margin-bottom: 5px;"><?php esc_html_e( 'End Date:', 'ffmpeg-media-optimizer' ); ?></label>
				<input type="date" name="end_date" style="height: 30px;" />
			</div>
			<div>
				<label style="font-weight:600; display:block; margin-bottom: 5px;"><?php esc_html_e( 'Report Format:', 'ffmpeg-media-optimizer' ); ?></label>
				<select name="format" style="height: 30px;">
					<option value="csv">CSV</option>
					<option value="json">JSON</option>
				</select>
			</div>
			<div>
				<button type="submit" class="button button-primary" style="height: 30px;"><?php esc_html_e( 'Export Performance Report', 'ffmpeg-media-optimizer' ); ?></button>
			</div>
		</form>
	</div>

	<!-- System Activity Logs console -->
	<div class="ffmpeg-logs-console" style="margin-top: 30px;">
		<div class="console-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
			<h2><?php esc_html_e( 'System Activity Log', 'ffmpeg-media-optimizer' ); ?></h2>
			<button type="button" class="button button-secondary" id="btn-clear-logs"><?php esc_html_e( 'Clear Logs', 'ffmpeg-media-optimizer' ); ?></button>
		</div>
		<textarea id="ffmpeg-logs-area" readonly rows="12" style="width:100%; font-family:Consolas, Monaco, monospace; font-size:12px; background:#111; color:#0f0; border-radius: 4px; padding: 10px; box-sizing: border-box; resize: vertical;"></textarea>
	</div>
</div>
