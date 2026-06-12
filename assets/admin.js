/**
 * JavaScript for FFmpeg Media Optimizer Admin interface.
 */

jQuery(document).ready(function($) {

	// 1. Media Library list view row actions
	$(document).on('click', '.ffmpeg-action-optimize', function(e) {
		e.preventDefault();
		var $link = $(this);
		var id = $link.data('id');
		var $row = $link.closest('tr');

		$link.text(ffmpegMediaSettings.messages.optimizing);
		
		$.post(ffmpegMediaSettings.ajaxurl, {
			action: 'ffmpeg_media_single_optimize',
			id: id,
			nonce: ffmpegMediaSettings.nonces.optimize
		}, function(response) {
			if (response.success) {
				// Update all custom columns cells (Feature 13)
				$row.find('.column-fmo_status').html(response.data.status_html);
				$row.find('.column-fmo_savings').html(response.data.savings_html);
				$row.find('.column-fmo_optimizer').html(response.data.opt_html);
				$row.find('.column-fmo_last').html(response.data.last_html);
				$row.find('.column-fmo_runs').html(response.data.runs_html);

				// Update row action link
				$link.parent().html('<a href="#" class="ffmpeg-action-restore" data-id="' + id + '">' + $link.text().replace(ffmpegMediaSettings.messages.optimizing, 'Restore Original') + '</a>');
			} else {
				alert(response.data.error || ffmpegMediaSettings.messages.error);
				$link.text('Optimize');
			}
		});
	});

	$(document).on('click', '.ffmpeg-action-restore', function(e) {
		e.preventDefault();
		var $link = $(this);
		var id = $link.data('id');
		var $row = $link.closest('tr');

		$link.text(ffmpegMediaSettings.messages.restoring);

		$.post(ffmpegMediaSettings.ajaxurl, {
			action: 'ffmpeg_media_single_restore',
			id: id,
			nonce: ffmpegMediaSettings.nonces.restore
		}, function(response) {
			if (response.success) {
				// Update all custom columns cells (Feature 13)
				$row.find('.column-fmo_status').html(response.data.status_html);
				$row.find('.column-fmo_savings').html(response.data.savings_html);
				$row.find('.column-fmo_optimizer').html(response.data.opt_html);
				$row.find('.column-fmo_last').html(response.data.last_html);
				$row.find('.column-fmo_runs').html(response.data.runs_html);

				$link.parent().html('<a href="#" class="ffmpeg-action-optimize" data-id="' + id + '">Optimize</a>');
			} else {
				alert(response.data.error || ffmpegMediaSettings.messages.error);
				$link.text('Restore Original');
			}
		});
	});

	// History details modal popup
	$(document).on('click', '.ffmpeg-action-history', function(e) {
		e.preventDefault();
		var id = $(this).data('id');

		$.post(ffmpegMediaSettings.ajaxurl, {
			action: 'ffmpeg_media_single_history',
			id: id,
			nonce: ffmpegMediaSettings.nonces.history
		}, function(response) {
			if (response.success) {
				var modalHtml = '<div id="ffmpeg-modal" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:99999;display:flex;align-items:center;justify-content:center;">' +
					'<div style="background:#fff;padding:25px;border-radius:6px;max-width:800px;width:90%;position:relative;box-shadow:0 4px 15px rgba(0,0,0,0.3);">' +
					'<span id="ffmpeg-modal-close" style="position:absolute;top:10px;right:15px;font-size:24px;cursor:pointer;color:#666;">&times;</span>' +
					'<h2>Optimization History Log</h2>' +
					'<div style="margin-top:15px;max-height:400px;overflow-y:auto;">' + response.data.html + '</div>' +
					'</div></div>';
				
				$('body').append(modalHtml);
			} else {
				alert(response.data.error || ffmpegMediaSettings.messages.error);
			}
		});
	});

	$(document).on('click', '#ffmpeg-modal-close, #ffmpeg-modal', function(e) {
		if (e.target === this) {
			$('#ffmpeg-modal').remove();
		}
	});

	// 2. Bulk page logic
	var $bulkWrap = $('.ffmpeg-bulk-wrap');
	if ($bulkWrap.length) {
		var bulkNonce = $bulkWrap.data('nonce');
		var statsTimer = null;
		var activeChartTab = 'daily';
		var chartData = { daily: [], monthly: [] };
		var lastQueueState = null;
		var isProcessingAjax = false;

		// Initial load
		refreshStats();

		// Scope switcher refreshes stats (Feature 5)
		$('#fmo-scope-select').change(function() {
			refreshStats();
		});

		// Scan Library
		$('#btn-scan').click(function() {
			var $btn = $(this);
			$btn.prop('disabled', true);
			$('#btn-optimize').prop('disabled', true);
			$('.ffmpeg-progress-wrapper').show();
			$('#progress-text').text('Scanning Media Library...');
			$('#progress-percent').text('0%');
			$('.ffmpeg-progress-bar-fill').css('width', '0%');

			runScanLoop(0);
		});

		function runScanLoop(offset) {
			$.post(ffmpegMediaSettings.ajaxurl, {
				action: 'ffmpeg_media_bulk_scan',
				offset: offset,
				nonce: bulkNonce
			}, function(response) {
				if (response.success) {
					if (response.data.done) {
						$('#progress-text').text('Scanning complete!');
						$('#progress-percent').text('100%');
						$('.ffmpeg-progress-bar-fill').css('width', '100%');
						$('#btn-scan').prop('disabled', false);
						setTimeout(function() {
							$('.ffmpeg-progress-wrapper').slideUp();
						}, 2000);
						refreshStats();
					} else {
						$('#progress-text').text('Scanning items (offset ' + response.data.offset + ')...');
						runScanLoop(response.data.offset);
					}
				} else {
					alert(response.data.error || 'Scan failed.');
					$('#btn-scan').prop('disabled', false);
				}
			});
		}

		// Operations controls
		$('#btn-optimize').click(function() {
			var scope = $('#fmo-scope-select').val() || 'all';
			controlQueue('start', scope);
		});

		$('#btn-pause').click(function() {
			controlQueue('pause');
		});

		$('#btn-resume').click(function() {
			controlQueue('resume');
		});

		$('#btn-stop').click(function() {
			if (confirm('Cancel background queue processing? Current progress is preserved.')) {
				controlQueue('stop');
			}
		});

		$('#btn-retry-failed').click(function() {
			var scope = $('#fmo-scope-select').val() || 'all';
			controlQueue('retry_failed', scope);
		});

		// Crash Recovery Event listeners (Feature 4)
		$('#btn-recovery-resume').click(function() {
			$('#fmo-recovery-warning').slideUp();
			controlQueue('resume');
		});

		$('#btn-recovery-restart').click(function() {
			$('#fmo-recovery-warning').slideUp();
			var scope = $('#fmo-scope-select').val() || 'all';
			controlQueue('restart', scope);
		});

		$('#btn-recovery-clear').click(function() {
			$('#fmo-recovery-warning').slideUp();
			controlQueue('clear');
		});

		function controlQueue(operation, scope) {
			scope = scope || 'all';

			// Immediately set local state status to prevent in-flight processes from scheduling
			if (operation === 'stop' || operation === 'clear') {
				lastQueueState.status = 'idle';
			} else if (operation === 'pause') {
				lastQueueState.status = 'paused';
			}

			$.post(ffmpegMediaSettings.ajaxurl, {
				action: 'ffmpeg_media_bulk_control',
				operation: operation,
				scope: scope,
				nonce: bulkNonce
			}, function(response) {
				if (response.success) {
					lastQueueState = response.data.state;
					updateControls(response.data.state);
					refreshStats();
					if ('running' === lastQueueState.status && !lastQueueState.interrupted) {
						if (!isProcessingAjax) {
							runAjaxProcess();
						}
					}
				} else {
					alert(response.data.error || 'Control operation failed.');
				}
			});
		}

		// Restore all
		$('#btn-restore-all').click(function() {
			if (confirm('Are you sure you want to restore all images from backups? This will revert optimized images back to their original size.')) {
				var $btn = $(this);
				$btn.prop('disabled', true);
				$.post(ffmpegMediaSettings.ajaxurl, {
					action: 'ffmpeg_media_bulk_restore',
					nonce: bulkNonce
				}, function(response) {
					$btn.prop('disabled', false);
					if (response.success) {
						alert(response.data.message);
						refreshStats();
					} else {
						alert(response.data.error || 'Restore failed.');
					}
				});
			}
		});

		// Clear Logs
		$('#btn-clear-logs').click(function() {
			if (confirm('Clear the log file?')) {
				$.post(ffmpegMediaSettings.ajaxurl, {
					action: 'ffmpeg_media_clear_logs',
					nonce: bulkNonce
				}, function(response) {
					if (response.success) {
						$('#ffmpeg-logs-area').val('');
					}
				});
			}
		});

		// Fetch stats & draw charts
		function refreshStats() {
			var scope = $('#fmo-scope-select').val() || 'all';
			$.post(ffmpegMediaSettings.ajaxurl, {
				action: 'ffmpeg_media_bulk_stats',
				scope: scope,
				nonce: bulkNonce
			}, function(response) {
				if (response.success) {
					var stats = response.data.stats;
					var queue = response.data.queue;

					$('#stat-total').text(stats.total);
					$('#stat-optimized').text(stats.optimized);
					$('#stat-pending').text(stats.pending);
					$('#stat-failed').text(stats.failed);
					$('#stat-savings').text(response.data.formatted.bytes_saved);
					$('#stat-avg').text(stats.average_savings + '%');

					// Display duplicates savings (Feature 9)
					if (stats.duplicate_count > 0) {
						$('#duplicate-desc').html('Saved: ' + response.data.formatted.bytes_saved + ' <br/><small style="color: #46b450;">(incl. ' + response.data.formatted.duplicate_saved + ' duplicate reuse)</small>');
					} else {
						$('#duplicate-desc').text('Total reclaimed storage.');
					}

					// WooCommerce specific metrics (Feature 5)
					if ($('#stat-wc-savings').length) {
						if (scope !== 'all') {
							$('#stat-wc-savings').text(response.data.formatted.bytes_saved);
						} else {
							// If entire library is selected, query base WooCommerce savings dynamically
							fetchBaseWcSavings();
						}
					}

					// Update log console
					$('#ffmpeg-logs-area').val(response.data.logs);

					// Load charts data (Feature 3)
					chartData.daily = response.data.daily || [];
					chartData.monthly = response.data.monthly || [];
					renderActiveChart();

					// Interruption Crash recovery banner check (Feature 4)
					if (queue.interrupted) {
						$('#fmo-recovery-warning').slideDown();
					} else {
						$('#fmo-recovery-warning').slideUp();
					}

					// Ensure we don't resurrect a queue that was stopped/paused locally
					if (lastQueueState && 'running' !== lastQueueState.status && 'running' === queue.status) {
						queue.status = lastQueueState.status;
					}

					// Manage optimize and retry failed button states (Feature 14)
					if (stats.pending > 0 && ('idle' === queue.status || 'stopped' === queue.status)) {
						$('#btn-optimize').prop('disabled', false);
					} else {
						$('#btn-optimize').prop('disabled', true);
					}

					if (stats.failed > 0 && ('idle' === queue.status || 'stopped' === queue.status)) {
						$('#btn-retry-failed').show();
					} else {
						$('#btn-retry-failed').hide();
					}

					lastQueueState = queue;
					updateControls(queue);
					if ('running' === queue.status && !queue.interrupted && !isProcessingAjax) {
						runAjaxProcess();
					}
				}
			});
		}

		function runAjaxProcess() {
			var state = lastQueueState || {};
			if ('running' !== state.status || state.interrupted) {
				isProcessingAjax = false;
				return;
			}

			isProcessingAjax = true;
			var scope = $('#fmo-scope-select').val() || 'all';

			$.post(ffmpegMediaSettings.ajaxurl, {
				action: 'ffmpeg_media_bulk_process',
				scope: scope,
				nonce: bulkNonce
			}, function(response) {
				// If the queue was stopped/paused locally while this request was in flight, discard response and exit
				if (lastQueueState && 'running' !== lastQueueState.status) {
					isProcessingAjax = false;
					return;
				}

				if (response.success) {
					lastQueueState = response.data.state;
					refreshStats();
					if ('running' === lastQueueState.status && !lastQueueState.interrupted) {
						setTimeout(runAjaxProcess, 100);
					} else {
						isProcessingAjax = false;
					}
				} else {
					isProcessingAjax = false;
					refreshStats();
				}
			}).fail(function() {
				// If the queue was stopped/paused locally while this request failed, do not retry
				if (lastQueueState && 'running' !== lastQueueState.status) {
					isProcessingAjax = false;
					return;
				}
				setTimeout(runAjaxProcess, 2000);
			});
		}

		// Pulls WooCommerce specific space saved metrics
		function fetchBaseWcSavings() {
			$.post(ffmpegMediaSettings.ajaxurl, {
				action: 'ffmpeg_media_bulk_stats',
				scope: 'wc',
				nonce: bulkNonce
			}, function(response) {
				if (response.success) {
					$('#stat-wc-savings').text(response.data.formatted.bytes_saved);
				}
			});
		}

		function updateControls(queue) {
			if ('running' === queue.status && !queue.interrupted) {
				$('#btn-scan').prop('disabled', true);
				$('#btn-optimize').hide();
				$('#btn-pause').show();
				$('#btn-resume').hide();
				$('#btn-stop').show();
				$('#btn-restore-all').prop('disabled', true);
				$('#fmo-scope-select').prop('disabled', true);

				// Show progress bar
				$('.ffmpeg-progress-wrapper').show();
				var total = parseInt(queue.total_jobs) || 0;
				var processed = parseInt(queue.processed_count) || 0;
				var pct = total > 0 ? Math.round((processed / total) * 100) : 0;

				$('#progress-text').text('Processing image queue (' + processed + '/' + total + ')...');
				$('#progress-percent').text(pct + '%');
				$('.ffmpeg-progress-bar-fill').css('width', pct + '%');

				if (queue.current_file) {
					$('#progress-current-filename').text(queue.current_file);
					if (queue.current_orig_size) {
						var sizeText = queue.current_orig_size;
						if (queue.current_opt_size) {
							sizeText += ' ➔ ' + queue.current_opt_size;
						}
						$('#progress-current-sizes').text(sizeText);
					}
					$('#progress-current-file-details').show();
				} else {
					$('#progress-current-file-details').hide();
				}

				if (!statsTimer) {
					statsTimer = setInterval(refreshStats, 3000);
				}
			} else if ('paused' === queue.status) {
				$('#btn-scan').prop('disabled', true);
				$('#btn-optimize').hide();
				$('#btn-pause').hide();
				$('#btn-resume').show();
				$('#btn-stop').show();
				$('#btn-restore-all').prop('disabled', true);
				$('#fmo-scope-select').prop('disabled', true);

				$('#progress-text').text('Optimization paused.');
				$('#progress-current-file-details').hide();
				if (statsTimer) {
					clearInterval(statsTimer);
					statsTimer = null;
				}
			} else {
				// idle, stopped, or interrupted
				$('#btn-scan').prop('disabled', false);
				$('#btn-optimize').show();
				$('#btn-pause').hide();
				$('#btn-resume').hide();
				$('#btn-stop').hide();
				$('#btn-restore-all').prop('disabled', false);
				$('#fmo-scope-select').prop('disabled', false);

				if (!queue.interrupted) {
					$('.ffmpeg-progress-wrapper').hide();
				}
				$('#progress-current-file-details').hide();
				if (statsTimer) {
					clearInterval(statsTimer);
					statsTimer = null;
				}
			}
		}

		// 3. Pure responsive SVG Chart drawing engine (Feature 3)
		$('.fmo-chart-tab').click(function() {
			$('.fmo-chart-tab').removeClass('active');
			$(this).addClass('active');
			activeChartTab = $(this).data('chart');
			renderActiveChart();
		});

		function renderActiveChart() {
			var data = activeChartTab === 'daily' ? chartData.daily : chartData.monthly;
			var $container = $('#fmo-svg-chart-container');
			$container.empty();

			if (!data || data.length === 0) {
				$container.html('<div style="text-align:center; padding-top:100px; color:#8c8f94; font-style:italic;">No performance data recorded for this period yet.</div>');
				return;
			}

			var width = $container.width() || 600;
			var height = 270;
			var padding = 45;

			// Get min/max coordinates
			var maxVal = 0;
			var labels = [];
			var points = [];

			data.forEach(function(item) {
				var bytes = parseFloat(item.bytes) || 0;
				if (bytes > maxVal) maxVal = bytes;
				labels.push(item.date_day || item.date_month);
				points.push(bytes);
			});

			if (maxVal === 0) maxVal = 1024 * 1024; // Default scale to 1MB if zero

			var svg = '<svg width="' + width + '" height="' + height + '" style="font-family: sans-serif; overflow: visible;">';

			// Draw grid Y lines & Labels
			var gridSteps = 4;
			for (var i = 0; i <= gridSteps; i++) {
				var y = padding + (height - padding * 2) * (1 - i / gridSteps);
				var gridVal = maxVal * (i / gridSteps);
				svg += '<line x1="' + padding + '" y1="' + y + '" x2="' + (width - padding) + '" y2="' + y + '" stroke="#e5e5e5" stroke-dasharray="4" />';
				svg += '<text x="' + (padding - 10) + '" y="' + (y + 4) + '" font-size="10" fill="#8c8f94" text-anchor="end">' + formatBytes(gridVal) + '</text>';
			}

			// Plot Points & Labels
			var stepX = (width - padding * 2) / (points.length > 1 ? points.length - 1 : 1);
			var pathPoints = [];

			points.forEach(function(val, index) {
				var x = padding + index * stepX;
				var y = padding + (height - padding * 2) * (1 - val / maxVal);
				pathPoints.push(x + ',' + y);

				// Draw points dots
				svg += '<circle cx="' + x + '" cy="' + y + '" r="4" fill="#2271b1" />';
				
				// Draw tooltips on hover
				svg += '<g class="chart-tooltip">';
				svg += '<rect x="' + (x - 40) + '" y="' + (y - 25) + '" width="80" height="18" rx="3" fill="#1d2327" style="display:none;" />';
				svg += '<text x="' + x + '" y="' + (y - 12) + '" fill="#fff" font-size="9" font-weight="bold" text-anchor="middle" style="display:none;">' + formatBytes(val) + '</text>';
				svg += '<circle cx="' + x + '" cy="' + y + '" r="10" fill="transparent" style="cursor:pointer;" ' +
					'onmouseover="this.previousSibling.style.display=\'block\'; this.previousSibling.previousSibling.style.display=\'block\';" ' +
					'onmouseout="this.previousSibling.style.display=\'none\'; this.previousSibling.previousSibling.style.display=\'none\';" />';
				svg += '</g>';

				// X Labels (only draw some to prevent overlapping)
				if (points.length < 15 || index % Math.ceil(points.length / 10) === 0 || index === points.length - 1) {
					var labelText = labels[index];
					if (activeChartTab === 'daily') {
						// Show only MM-DD
						labelText = labelText.substring(5);
					}
					svg += '<text x="' + x + '" y="' + (height - padding + 15) + '" font-size="10" fill="#8c8f94" text-anchor="middle" transform="rotate(25, ' + x + ', ' + (height - padding + 15) + ')">' + labelText + '</text>';
				}
			});

			// Draw connection line
			svg += '<path d="M ' + pathPoints.join(' L ') + '" fill="none" stroke="#2271b1" stroke-width="3" />';
			svg += '</svg>';

			$container.html(svg);
		}

		function formatBytes(bytes) {
			if (bytes === 0) return '0 B';
			var k = 1024;
			var sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
			var i = Math.floor(Math.log(bytes) / Math.log(k));
			return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
		}
	}
});
