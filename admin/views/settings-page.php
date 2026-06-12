<?php
/**
 * Settings page view template.
 *
 * @package    FFmpeg_Media_Optimizer
 */

namespace FfmpegMediaOptimizer\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = Settings::get_settings();
$specs    = Settings::get_server_specs();
$rec      = Settings::get_recommended_profile();

$profiles_info = array(
	'low'      => array( 'label' => __( 'Low Resource VPS (1-2 vCPU, 1-4 GB RAM)', 'ffmpeg-media-optimizer' ), 'jobs' => 1, 'batch' => 5, 'priority' => 'low' ),
	'balanced' => array( 'label' => __( 'Balanced (2-4 vCPU, 4-8 GB RAM)', 'ffmpeg-media-optimizer' ), 'jobs' => 2, 'batch' => 20, 'priority' => 'normal' ),
	'high'     => array( 'label' => __( 'High Performance (4+ vCPU, 8+ GB RAM)', 'ffmpeg-media-optimizer' ), 'jobs' => 4, 'batch' => 50, 'priority' => 'high' ),
);
?>
<div class="wrap ffmpeg-settings-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<!-- Server Specs Recommendation Banner (Feature 10) -->
	<div class="notice notice-info" style="margin-top: 15px; border-left-color: #9b51e0; padding: 12px 18px;">
		<p style="margin: 0; font-size: 14px;">
			<span class="dashicons dashicons-performance" style="vertical-align: middle; margin-right: 5px; color: #9b51e0;"></span>
			<strong><?php esc_html_e( 'Server Recommendation Engine:', 'ffmpeg-media-optimizer' ); ?></strong>
			<?php
			printf(
				/* translators: 1: CPU core count, 2: RAM capacity in GB, 3: recommended profile name */
				esc_html__( 'Your server has %1$d CPU core(s) and %2$s GB RAM. Recommended profile: %3$s.', 'ffmpeg-media-optimizer' ),
				(int) $specs['cores'],
				esc_html( $specs['ram'] ),
				'<strong>' . esc_html( ucfirst( $rec ) ) . '</strong>'
			);
			?>
		</p>
	</div>

	<h2 class="nav-tab-wrapper" style="margin-top: 15px;">
		<a href="#tab-general" class="nav-tab nav-tab-active"><?php esc_html_e( 'General', 'ffmpeg-media-optimizer' ); ?></a>
		<a href="#tab-compression" class="nav-tab"><?php esc_html_e( 'Compression', 'ffmpeg-media-optimizer' ); ?></a>
		<a href="#tab-limits" class="nav-tab"><?php esc_html_e( 'Limits', 'ffmpeg-media-optimizer' ); ?></a>
		<a href="#tab-automation" class="nav-tab"><?php esc_html_e( 'Automation', 'ffmpeg-media-optimizer' ); ?></a>
		<a href="#tab-backup" class="nav-tab"><?php esc_html_e( 'Backup', 'ffmpeg-media-optimizer' ); ?></a>
		<a href="#tab-advanced" class="nav-tab"><?php esc_html_e( 'Advanced', 'ffmpeg-media-optimizer' ); ?></a>
	</h2>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'ffmpeg_media_optimizer_settings' );
		?>

		<!-- Tab 1: General -->
		<div id="tab-general" class="tab-content">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Enable Optimization', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[enabled]" value="1" <?php checked( 1, $settings['enabled'] ); ?> />
						<p class="description"><?php esc_html_e( 'Activate automatic or bulk processing.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>

				<!-- VPS Profiles (Feature 2) -->
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Server Profile', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<select name="ffmpeg_media_optimizer_settings[profile]" id="fmo-profile-select">
							<option value="low" <?php selected( 'low', $settings['profile'] ); ?>><?php esc_html_e( 'Low Resource VPS', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="balanced" <?php selected( 'balanced', $settings['profile'] ); ?>><?php esc_html_e( 'Balanced', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="high" <?php selected( 'high', $settings['profile'] ); ?>><?php esc_html_e( 'High Performance', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="custom" <?php selected( 'custom', $settings['profile'] ); ?>><?php esc_html_e( 'Custom Tuning', 'ffmpeg-media-optimizer' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Choose a performance profile. Changing profile updates Concurrent Jobs, Batch Size and CPU Priority recommended settings below.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label><?php esc_html_e( 'Max Concurrent Jobs', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[max_concurrent_jobs]" id="fmo-jobs-input" value="<?php echo esc_attr( $settings['max_concurrent_jobs'] ); ?>" min="1" max="10" class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of images processed in parallel during a single batch loop.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Batch Size', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[batch_size]" id="fmo-batch-input" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="1" max="500" class="small-text" />
						<p class="description"><?php esc_html_e( 'Total files optimized during each cron cycle.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Processing Timeout', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[processing_timeout]" value="<?php echo esc_attr( $settings['processing_timeout'] ); ?>" min="5" max="300" class="small-text" />
						<span><?php esc_html_e( 'seconds', 'ffmpeg-media-optimizer' ); ?></span>
						<p class="description"><?php esc_html_e( 'Kill binary process if single image execution exceeds this duration.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'CPU Priority', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<select name="ffmpeg_media_optimizer_settings[cpu_priority]" id="fmo-priority-select">
							<option value="low" <?php selected( 'low', $settings['cpu_priority'] ); ?>><?php esc_html_e( 'Low (Nice)', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="normal" <?php selected( 'normal', $settings['cpu_priority'] ); ?>><?php esc_html_e( 'Normal', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="high" <?php selected( 'high', $settings['cpu_priority'] ); ?>><?php esc_html_e( 'High', 'ffmpeg-media-optimizer' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Niceness scheduling window for system cores load control.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>

				<!-- WooCommerce Scope (Feature 5) -->
				<tr>
					<th scope="row"><label><?php esc_html_e( 'WooCommerce Scope', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<?php
						$wc_active = class_exists( 'WooCommerce' );
						$disabled  = $wc_active ? '' : 'disabled="disabled"';
						?>
						<select name="ffmpeg_media_optimizer_settings[wc_scope]" <?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<option value="all" <?php selected( 'all', $settings['wc_scope'] ); ?>><?php esc_html_e( 'Entire Library', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="wc" <?php selected( 'wc', $settings['wc_scope'] ); ?>><?php esc_html_e( 'WooCommerce Only (All Products & categories)', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="products" <?php selected( 'products', $settings['wc_scope'] ); ?>><?php esc_html_e( 'Product Featured Images Only', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="galleries" <?php selected( 'galleries', $settings['wc_scope'] ); ?>><?php esc_html_e( 'Product Galleries Only', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="categories" <?php selected( 'categories', $settings['wc_scope'] ); ?>><?php esc_html_e( 'Category Images Only', 'ffmpeg-media-optimizer' ); ?></option>
						</select>
						<p class="description">
							<?php
							if ( $wc_active ) {
								esc_html_e( 'Select the default scope of images optimized by the background processing queues.', 'ffmpeg-media-optimizer' );
							} else {
								echo '<strong style="color: #dc3232;">' . esc_html__( 'WooCommerce is not active on this site.', 'ffmpeg-media-optimizer' ) . '</strong>';
							}
							?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Tab 2: Compression -->
		<div id="tab-compression" class="tab-content" style="display:none;">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'JPEG Quality', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[jpeg_quality]" value="<?php echo esc_attr( $settings['jpeg_quality'] ); ?>" min="1" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'Target quality for lossy JPEG processes (1-100, default is 82).', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'PNG Compression Level', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[png_compression]" value="<?php echo esc_attr( $settings['png_compression'] ); ?>" min="0" max="9" class="small-text" />
						<p class="description"><?php esc_html_e( 'FFmpeg compression scale (0-9). For optipng, values map to optimization pass levels (2-7).', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'WebP Quality', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[webp_quality]" value="<?php echo esc_attr( $settings['webp_quality'] ); ?>" min="1" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'Target quality for WebP generation (1-100, default is 80).', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'AVIF Quality', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[avif_quality]" value="<?php echo esc_attr( $settings['avif_quality'] ); ?>" min="1" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'AVIF target compression quality (1-100, default is 65). Requires AVIF codecs support in FFmpeg.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Conversion Target', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<select name="ffmpeg_media_optimizer_settings[conversion_target]">
							<option value="original" <?php selected( 'original', $settings['conversion_target'] ); ?>><?php esc_html_e( 'Keep Original Formats Only', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="webp" <?php selected( 'webp', $settings['conversion_target'] ); ?>><?php esc_html_e( 'Generate WebP alongside original', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="avif" <?php selected( 'avif', $settings['conversion_target'] ); ?>><?php esc_html_e( 'Generate AVIF alongside original', 'ffmpeg-media-optimizer' ); ?></option>
							<option value="best" <?php selected( 'best', $settings['conversion_target'] ); ?>><?php esc_html_e( 'Generate WebP & AVIF alongside original', 'ffmpeg-media-optimizer' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Output next-gen formats alongside compressed files.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Tab 3: Limits -->
		<div id="tab-limits" class="tab-content" style="display:none;">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Minimum File Size', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[min_file_size]" value="<?php echo esc_attr( $settings['min_file_size'] ); ?>" class="regular-text" />
						<span><?php esc_html_e( 'bytes', 'ffmpeg-media-optimizer' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Maximum File Size', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[max_file_size]" value="<?php echo esc_attr( $settings['max_file_size'] ); ?>" class="regular-text" />
						<span><?php esc_html_e( 'bytes', 'ffmpeg-media-optimizer' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Minimum Dimensions', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[min_width]" value="<?php echo esc_attr( $settings['min_width'] ); ?>" min="0" class="small-text" />
						x
						<input type="number" name="ffmpeg_media_optimizer_settings[min_height]" value="<?php echo esc_attr( $settings['min_height'] ); ?>" min="0" class="small-text" />
						<span><?php esc_html_e( 'pixels (Width x Height)', 'ffmpeg-media-optimizer' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Excluded Mime Types', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<?php
						$mimes = array(
							'image/jpeg' => 'JPEG',
							'image/png'  => 'PNG',
							'image/webp' => 'WebP',
							'image/avif' => 'AVIF',
							'image/gif'  => 'GIF',
						);
						foreach ( $mimes as $mime => $label ) :
							$checked = in_array( $mime, $settings['excluded_mimes'], true ) ? 'checked="checked"' : '';
							?>
							<label style="margin-right: 15px;">
								<input type="checkbox" name="ffmpeg_media_optimizer_settings[excluded_mimes][]" value="<?php echo esc_attr( $mime ); ?>" <?php echo $checked; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
		</div>

		<!-- Tab 4: Automation & Scheduled Windows -->
		<div id="tab-automation" class="tab-content" style="display:none;">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Automation Mode', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="ffmpeg_media_optimizer_settings[optimize_on_upload]" value="1" <?php checked( 1, $settings['optimize_on_upload'] ); ?> />
								<?php esc_html_e( 'Optimize on upload (Queues automatically on upload)', 'ffmpeg-media-optimizer' ); ?>
							</label><br/>
							<label style="margin-top: 10px; display: inline-block;">
								<input type="checkbox" name="ffmpeg_media_optimizer_settings[optimize_regenerated]" value="1" <?php checked( 1, $settings['optimize_regenerated'] ); ?> />
								<?php esc_html_e( 'Optimize regenerated thumbnails (Queues when metadata changes)', 'ffmpeg-media-optimizer' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>

				<!-- Scheduled Windows (Feature 8) -->
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Optimization Window', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[night_schedule]" id="ffmpeg-night-schedule" value="1" <?php checked( 1, $settings['night_schedule'] ); ?> />
						<label for="ffmpeg-night-schedule"><?php esc_html_e( 'Only process background jobs during specific hours', 'ffmpeg-media-optimizer' ); ?></label>
						
						<div class="ffmpeg-night-hours" style="margin-top:10px; <?php echo $settings['night_schedule'] ? '' : 'display:none;'; ?>">
							<input type="text" name="ffmpeg_media_optimizer_settings[night_start]" value="<?php echo esc_attr( $settings['night_start'] ); ?>" class="small-text" placeholder="22:00" />
							<?php esc_html_e( 'to', 'ffmpeg-media-optimizer' ); ?>
							<input type="text" name="ffmpeg_media_optimizer_settings[night_end]" value="<?php echo esc_attr( $settings['night_end'] ); ?>" class="small-text" placeholder="06:00" />
							
							<div style="margin-top: 10px;">
								<label><?php esc_html_e( 'Timezone Settings', 'ffmpeg-media-optimizer' ); ?></label> <br/>
								<select name="ffmpeg_media_optimizer_settings[timezone]">
									<?php
									$timezones = timezone_identifiers_list();
									foreach ( $timezones as $tz ) {
										printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $tz ), selected( $tz, $settings['timezone'], false ) );
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'Select timezone mapping (System automatically pulls local WP offset if kept standard).', 'ffmpeg-media-optimizer' ); ?></p>
							</div>
						</div>
					</td>
				</tr>
			</table>
		</div>

		<!-- Tab 5: Backup -->
		<div id="tab-backup" class="tab-content" style="display:none;">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Enable Backups', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[enable_backups]" value="1" <?php checked( 1, $settings['enable_backups'] ); ?> />
						<p class="description"><?php esc_html_e( 'Keep pre-optimized copies of original uploads to support restoration.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Backup Retention Period', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[backup_retention]" value="<?php echo esc_attr( $settings['backup_retention'] ); ?>" min="1" class="small-text" />
						<span><?php esc_html_e( 'days', 'ffmpeg-media-optimizer' ); ?></span>
					</td>
				</tr>
			</table>
		</div>

		<!-- Tab 6: Advanced (Custom paths and logging) -->
		<div id="tab-advanced" class="tab-content" style="display:none;">
			<table class="form-table" role="presentation">
				<!-- Custom Binary Paths inputs (Feature 1) -->
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Custom Binaries Paths', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<fieldset style="border: 1px solid #ccc; padding: 15px; border-radius: 4px;">
							<legend style="padding: 0 8px; font-weight: 600;"><?php esc_html_e( 'Absolute Binary Paths', 'ffmpeg-media-optimizer' ); ?></legend>
							<div>
								<label style="display: inline-block; width: 120px; font-weight:600;">FFmpeg:</label>
								<input type="text" name="ffmpeg_media_optimizer_settings[ffmpeg_path]" value="<?php echo esc_attr( $settings['ffmpeg_path'] ); ?>" class="regular-text" placeholder="ffmpeg" />
							</div>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Provide custom absolute paths if binaries are not registered in system PATH environment.', 'ffmpeg-media-optimizer' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label><?php esc_html_e( 'WP-CLI Integration', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[enable_cli]" value="1" <?php checked( 1, $settings['enable_cli'] ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Enable Logging', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[enable_logging]" value="1" <?php checked( 1, $settings['enable_logging'] ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Debug Mode', 'ffmpeg-media-optimizer' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[debug_mode]" value="1" <?php checked( 1, $settings['debug_mode'] ); ?> />
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button(); ?>
	</form>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
	// Tab switching
	$('.nav-tab-wrapper a').click(function(e) {
		e.preventDefault();
		$('.nav-tab-wrapper a').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		
		var target = $(this).attr('href');
		$('.tab-content').hide();
		$(target).show();
	});

	// Night scheduling window toggle
	$('#ffmpeg-night-schedule').change(function() {
		if ($(this).is(':checked')) {
			$('.ffmpeg-night-hours').slideDown();
		} else {
			$('.ffmpeg-night-hours').slideUp();
		}
	});

	// VPS profile automation updater (Feature 2)
	var profiles = <?php echo wp_json_encode( $profiles_info ); ?>;
	$('#fmo-profile-select').change(function() {
		var val = $(this).val();
		if (val && val !== 'custom') {
			var config = profiles[val];
			if (config) {
				$('#fmo-jobs-input').val(config.jobs);
				$('#fmo-batch-input').val(config.batch);
				$('#fmo-priority-select').val(config.priority);
			}
		}
	});
});
</script>
