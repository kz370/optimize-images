<?php
/**
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
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
	'low'      => array( 'label' => __( 'Low Resource VPS (1-2 vCPU, 1-4 GB RAM)', 'optimize-images' ), 'jobs' => 1, 'batch' => 5, 'priority' => 'low' ),
	'balanced' => array( 'label' => __( 'Balanced (2-4 vCPU, 4-8 GB RAM)', 'optimize-images' ), 'jobs' => 2, 'batch' => 20, 'priority' => 'normal' ),
	'high'     => array( 'label' => __( 'High Performance (4+ vCPU, 8+ GB RAM)', 'optimize-images' ), 'jobs' => 4, 'batch' => 50, 'priority' => 'high' ),
);
?>
<div class="wrap ffmpeg-settings-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<!-- Server Specs Recommendation Banner (Feature 10) -->
	<div class="notice notice-info" style="margin-top: 15px; border-left-color: #9b51e0; padding: 12px 18px;">
		<p style="margin: 0; font-size: 14px;">
			<span class="dashicons dashicons-performance" style="vertical-align: middle; margin-right: 5px; color: #9b51e0;"></span>
			<strong><?php esc_html_e( 'Server Recommendation Engine:', 'optimize-images' ); ?></strong>
			<?php
			printf(
				/* translators: 1: CPU core count, 2: RAM capacity in GB, 3: recommended profile name */
				esc_html__( 'Your server has %1$d CPU core(s) and %2$s GB RAM. Recommended profile: %3$s.', 'optimize-images' ),
				(int) $specs['cores'],
				esc_html( $specs['ram'] ),
				'<strong>' . esc_html( ucfirst( $rec ) ) . '</strong>'
			);
			?>
		</p>
	</div>

	<h2 class="nav-tab-wrapper" style="margin-top: 15px;">
		<a href="#tab-general" class="nav-tab nav-tab-active"><?php esc_html_e( 'General', 'optimize-images' ); ?></a>
		<a href="#tab-compression" class="nav-tab"><?php esc_html_e( 'Compression', 'optimize-images' ); ?></a>
		<a href="#tab-limits" class="nav-tab"><?php esc_html_e( 'Limits', 'optimize-images' ); ?></a>
		<a href="#tab-automation" class="nav-tab"><?php esc_html_e( 'Automation', 'optimize-images' ); ?></a>
		<a href="#tab-backup" class="nav-tab"><?php esc_html_e( 'Backup', 'optimize-images' ); ?></a>
		<a href="#tab-advanced" class="nav-tab"><?php esc_html_e( 'Advanced', 'optimize-images' ); ?></a>
	</h2>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'ffmpeg_media_optimizer_settings' );
		?>

		<!-- Tab 1: General -->
		<div id="tab-general" class="tab-content">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Enable Optimization', 'optimize-images' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[enabled]" value="1" <?php checked( 1, $settings['enabled'] ); ?> />
						<p class="description"><?php esc_html_e( 'Activate automatic or bulk processing.', 'optimize-images' ); ?></p>
					</td>
				</tr>

				<!-- VPS Profiles (Feature 2) -->
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Server Profile', 'optimize-images' ); ?></label></th>
					<td>
						<select name="ffmpeg_media_optimizer_settings[profile]" id="fmo-profile-select">
							<option value="low" <?php selected( 'low', $settings['profile'] ); ?>><?php esc_html_e( 'Low Resource VPS', 'optimize-images' ); ?></option>
							<option value="balanced" <?php selected( 'balanced', $settings['profile'] ); ?>><?php esc_html_e( 'Balanced', 'optimize-images' ); ?></option>
							<option value="high" <?php selected( 'high', $settings['profile'] ); ?>><?php esc_html_e( 'High Performance', 'optimize-images' ); ?></option>
							<option value="custom" <?php selected( 'custom', $settings['profile'] ); ?>><?php esc_html_e( 'Custom Tuning', 'optimize-images' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Choose a performance profile. Changing profile updates Concurrent Jobs, Batch Size and CPU Priority recommended settings below.', 'optimize-images' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label><?php esc_html_e( 'Max Concurrent Jobs', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[max_concurrent_jobs]" id="fmo-jobs-input" value="<?php echo esc_attr( $settings['max_concurrent_jobs'] ); ?>" min="1" max="10" class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of images processed in parallel during a single batch loop.', 'optimize-images' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Batch Size', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[batch_size]" id="fmo-batch-input" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="1" max="500" class="small-text" />
						<p class="description"><?php esc_html_e( 'Total files optimized during each cron cycle.', 'optimize-images' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Processing Timeout', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[processing_timeout]" value="<?php echo esc_attr( $settings['processing_timeout'] ); ?>" min="5" max="300" class="small-text" />
						<span><?php esc_html_e( 'seconds', 'optimize-images' ); ?></span>
						<p class="description"><?php esc_html_e( 'Kill binary process if single image execution exceeds this duration.', 'optimize-images' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'CPU Priority', 'optimize-images' ); ?></label></th>
					<td>
						<select name="ffmpeg_media_optimizer_settings[cpu_priority]" id="fmo-priority-select">
							<option value="low" <?php selected( 'low', $settings['cpu_priority'] ); ?>><?php esc_html_e( 'Low (Nice)', 'optimize-images' ); ?></option>
							<option value="normal" <?php selected( 'normal', $settings['cpu_priority'] ); ?>><?php esc_html_e( 'Normal', 'optimize-images' ); ?></option>
							<option value="high" <?php selected( 'high', $settings['cpu_priority'] ); ?>><?php esc_html_e( 'High', 'optimize-images' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Niceness scheduling window for system cores load control.', 'optimize-images' ); ?></p>
					</td>
				</tr>

				<!-- WooCommerce Scope (Feature 5) -->
				<tr>
					<th scope="row"><label><?php esc_html_e( 'WooCommerce Scope', 'optimize-images' ); ?></label></th>
					<td>
						<?php
						$wc_active = class_exists( 'WooCommerce' );
						$disabled  = $wc_active ? '' : 'disabled="disabled"';
						?>
						<select name="ffmpeg_media_optimizer_settings[wc_scope]" <?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<option value="all" <?php selected( 'all', $settings['wc_scope'] ); ?>><?php esc_html_e( 'Entire Library', 'optimize-images' ); ?></option>
							<option value="wc" <?php selected( 'wc', $settings['wc_scope'] ); ?>><?php esc_html_e( 'WooCommerce Only (All Products & categories)', 'optimize-images' ); ?></option>
							<option value="products" <?php selected( 'products', $settings['wc_scope'] ); ?>><?php esc_html_e( 'Product Featured Images Only', 'optimize-images' ); ?></option>
							<option value="galleries" <?php selected( 'galleries', $settings['wc_scope'] ); ?>><?php esc_html_e( 'Product Galleries Only', 'optimize-images' ); ?></option>
							<option value="categories" <?php selected( 'categories', $settings['wc_scope'] ); ?>><?php esc_html_e( 'Category Images Only', 'optimize-images' ); ?></option>
						</select>
						<p class="description">
							<?php
							if ( $wc_active ) {
								esc_html_e( 'Select the default scope of images optimized by the background processing queues.', 'optimize-images' );
							} else {
								echo '<strong style="color: #dc3232;">' . esc_html__( 'WooCommerce is not active on this site.', 'optimize-images' ) . '</strong>';
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
					<th scope="row"><label><?php esc_html_e( 'JPEG Quality', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[jpeg_quality]" value="<?php echo esc_attr( $settings['jpeg_quality'] ); ?>" min="1" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'Target quality for lossy JPEG processes (1-100, default is 82).', 'optimize-images' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'PNG Compression Level', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[png_compression]" value="<?php echo esc_attr( $settings['png_compression'] ); ?>" min="0" max="9" class="small-text" />
						<p class="description"><?php esc_html_e( 'FFmpeg compression scale (0-9). For optipng, values map to optimization pass levels (2-7).', 'optimize-images' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'WebP Quality', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[webp_quality]" value="<?php echo esc_attr( $settings['webp_quality'] ); ?>" min="1" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'Target quality for WebP generation (1-100, default is 80).', 'optimize-images' ); ?></p>
			</td>
			</tr>
			<tr>
				<th scope="row"><label><?php esc_html_e( 'Replace Original with WebP', 'optimize-images' ); ?></label></th>
				<td>
					<input type="checkbox" name="ffmpeg_media_optimizer_settings[replace_original_with_webp]" value="1" <?php checked( 1, $settings['replace_original_with_webp'] ); ?> />
					<p class="description"><?php esc_html_e( 'When enabled, the original JPEG/PNG file will be replaced by the generated WebP file (original file is backed up if backups are enabled).', 'optimize-images' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'AVIF Quality', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[avif_quality]" value="<?php echo esc_attr( $settings['avif_quality'] ); ?>" min="1" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'AVIF target compression quality (1-100, default is 65). Requires AVIF codecs support in FFmpeg.', 'optimize-images' ); ?></p>
					</td>
				</tr>

			</table>
		</div>

		<!-- Tab 3: Limits -->
		<div id="tab-limits" class="tab-content" style="display:none;">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Minimum File Size', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[min_file_size]" value="<?php echo esc_attr( $settings['min_file_size'] ); ?>" class="regular-text" />
						<span><?php esc_html_e( 'bytes', 'optimize-images' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Maximum File Size', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[max_file_size]" value="<?php echo esc_attr( $settings['max_file_size'] ); ?>" class="regular-text" />
						<span><?php esc_html_e( 'bytes', 'optimize-images' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Minimum Dimensions', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[min_width]" value="<?php echo esc_attr( $settings['min_width'] ); ?>" min="0" class="small-text" />
						x
						<input type="number" name="ffmpeg_media_optimizer_settings[min_height]" value="<?php echo esc_attr( $settings['min_height'] ); ?>" min="0" class="small-text" />
						<span><?php esc_html_e( 'pixels (Width x Height)', 'optimize-images' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Excluded Mime Types', 'optimize-images' ); ?></label></th>
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
					<th scope="row"><label><?php esc_html_e( 'Automation Mode', 'optimize-images' ); ?></label></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="ffmpeg_media_optimizer_settings[optimize_on_upload]" value="1" <?php checked( 1, $settings['optimize_on_upload'] ); ?> />
								<?php esc_html_e( 'Optimize on upload (Queues automatically on upload)', 'optimize-images' ); ?>
							</label><br/>
							<label style="margin-top: 10px; display: inline-block;">
								<input type="checkbox" name="ffmpeg_media_optimizer_settings[optimize_regenerated]" value="1" <?php checked( 1, $settings['optimize_regenerated'] ); ?> />
								<?php esc_html_e( 'Optimize regenerated thumbnails (Queues when metadata changes)', 'optimize-images' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>

				<!-- Scheduled Windows (Feature 8) -->
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Optimization Window', 'optimize-images' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[night_schedule]" id="ffmpeg-night-schedule" value="1" <?php checked( 1, $settings['night_schedule'] ); ?> />
						<label for="ffmpeg-night-schedule"><?php esc_html_e( 'Only process background jobs during specific hours', 'optimize-images' ); ?></label>
						
						<div class="ffmpeg-night-hours" style="margin-top:10px; <?php echo $settings['night_schedule'] ? '' : 'display:none;'; ?>">
							<input type="text" name="ffmpeg_media_optimizer_settings[night_start]" value="<?php echo esc_attr( $settings['night_start'] ); ?>" class="small-text" placeholder="22:00" />
							<?php esc_html_e( 'to', 'optimize-images' ); ?>
							<input type="text" name="ffmpeg_media_optimizer_settings[night_end]" value="<?php echo esc_attr( $settings['night_end'] ); ?>" class="small-text" placeholder="06:00" />
							
							<div style="margin-top: 10px;">
								<label><?php esc_html_e( 'Timezone Settings', 'optimize-images' ); ?></label> <br/>
								<select name="ffmpeg_media_optimizer_settings[timezone]">
									<?php
									$timezones = timezone_identifiers_list();
									foreach ( $timezones as $tz ) {
										printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $tz ), selected( $tz, $settings['timezone'], false ) );
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'Select timezone mapping (System automatically pulls local WP offset if kept standard).', 'optimize-images' ); ?></p>
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
					<th scope="row"><label><?php esc_html_e( 'Enable Backups', 'optimize-images' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[enable_backups]" value="1" <?php checked( 1, $settings['enable_backups'] ); ?> />
						<p class="description"><?php esc_html_e( 'Keep pre-optimized copies of original uploads to support restoration.', 'optimize-images' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Backup Retention Period', 'optimize-images' ); ?></label></th>
					<td>
						<input type="number" name="ffmpeg_media_optimizer_settings[backup_retention]" value="<?php echo esc_attr( $settings['backup_retention'] ); ?>" min="1" class="small-text" />
						<span><?php esc_html_e( 'days', 'optimize-images' ); ?></span>
					</td>
				</tr>
			</table>
		</div>

		<!-- Tab 6: Advanced (Custom paths and logging) -->
		<div id="tab-advanced" class="tab-content" style="display:none;">
			<table class="form-table" role="presentation">
				<!-- Custom Binary Paths inputs (Feature 1) -->
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Custom Binaries Paths', 'optimize-images' ); ?></label></th>
					<td>
						<fieldset style="border: 1px solid #ccc; padding: 15px; border-radius: 4px;">
							<legend style="padding: 0 8px; font-weight: 600;"><?php esc_html_e( 'Absolute Binary Paths', 'optimize-images' ); ?></legend>
							<div>
								<label style="display: inline-block; width: 120px; font-weight:600;">FFmpeg:</label>
								<input type="text" name="ffmpeg_media_optimizer_settings[ffmpeg_path]" value="<?php echo esc_attr( $settings['ffmpeg_path'] ); ?>" class="regular-text" placeholder="ffmpeg" />
							</div>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Provide custom absolute paths if binaries are not registered in system PATH environment.', 'optimize-images' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label><?php esc_html_e( 'WP-CLI Integration', 'optimize-images' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[enable_cli]" value="1" <?php checked( 1, $settings['enable_cli'] ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Enable Logging', 'optimize-images' ); ?></label></th>
					<td>
						<input type="checkbox" name="ffmpeg_media_optimizer_settings[enable_logging]" value="1" <?php checked( 1, $settings['enable_logging'] ); ?> />
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Debug Mode', 'optimize-images' ); ?></label></th>
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
