/**
 * Client side actions and AJAX triggers for Clear and Purge Cache.
 *
 * @package Clear_And_Purge_Cache
 * @since   1.0.0
 */

jQuery(document).ready(function($) {

	// Tab toggler logic
	$('.cpc-tab-wrapper a').on('click', function(e) {
		e.preventDefault();
		$('.cpc-tab-wrapper a').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');

		var tab = $(this).data('tab');
		$('.cpc-tab-content').removeClass('cpc-tab-active');
		$('#cpc-tab-' + tab).addClass('cpc-tab-active');
		window.location.hash = 'tab-' + tab;
	});

	// Support direct links to tabs
	var hash = window.location.hash;
	if (hash) {
		var tabName = hash.replace('#tab-', '');
		var $tab = $('.cpc-tab-wrapper a[data-tab="' + tabName + '"]');
		if ($tab.length) {
			$tab.trigger('click');
		}
	}

	// Settings Form AJAX Save Trigger
	$('#cpc-settings-form').on('submit', function(e) {
		e.preventDefault();
		var $btn = $(this).find('.cpc-btn-save-settings');
		var oldText = $btn.text();

		$btn.text('Saving Configuration...').prop('disabled', true);

		var formData = $(this).serializeArray();
		formData.push({ name: 'action', value: 'cpc_save_settings' });
		formData.push({ name: 'nonce', value: cpc_vars.nonce });

		$.post(cpc_vars.ajax_url, formData, function(res) {
			if (res.success) {
				alert(res.data.message);
			} else {
				alert('Error: ' + res.data.message);
			}
			$btn.text(oldText).prop('disabled', false);
		}).fail(function() {
			alert('AJAX request failed.');
			$btn.text(oldText).prop('disabled', false);
		});
	});
});

/**
 * Trigger global clear cache routine.
 */
function CPC_Trigger_Clear_All() {
	var $ = jQuery;
	if (!confirm('Are you sure you want to purge all page caches?')) {
		return;
	}

	$.post(cpc_vars.ajax_url, {
		action: 'cpc_clear_all_cache',
		nonce: cpc_vars.nonce
	}, function(res) {
		if (res.success) {
			alert(res.data.message);
			// Reset statistics count displays
			$('#cpc-stat-desktop-size').html('0 <span>files compiled</span>');
			$('#cpc-stat-mobile-size').html('0 <span>files compiled</span>');
		} else {
			alert('Error: ' + res.data.message);
		}
	});
}

/**
 * Trigger specific front-end page cache clearance.
 */
function CPC_Trigger_Clear_Page(postId) {
	var $ = jQuery;
	$.post(cpc_vars.ajax_url, {
		action: 'cpc_clear_page_cache',
		post_id: postId,
		url: postId ? '' : window.location.href,
		nonce: cpc_vars.nonce
	}, function(res) {
		if (res.success) {
			alert(res.data.message);
		} else {
			alert('Error: ' + res.data.message);
		}
	});
}

/**
 * Trigger minified CSS/JS and page cache clearance.
 */
function CPC_Trigger_Clear_Minified() {
	var $ = jQuery;
	if (!confirm('Are you sure you want to purge compiled asset folders and page caches?')) {
		return;
	}

	$.post(cpc_vars.ajax_url, {
		action: 'cpc_clear_minified_cache',
		nonce: cpc_vars.nonce
	}, function(res) {
		if (res.success) {
			alert(res.data.message);
			// Reset stats counters
			$('#cpc-stat-desktop-size').html('0 <span>files compiled</span>');
			$('#cpc-stat-mobile-size').html('0 <span>files compiled</span>');
			$('#cpc-stat-css-size').html('0 <span>stylesheets minified</span>');
			$('#cpc-stat-js-size').html('0 <span>script builds</span>');
		} else {
			alert('Error: ' + res.data.message);
		}
	});
}

/**
 * Trigger Database Optimization Sweep.
 */
function CPC_Trigger_Optimize_DB() {
	var $ = jQuery;
	var $btn = $('.cpc-btn-optimize-db');
	var $loader = $('.cpc-db-loader-indicator');
	var $resultsBox = $('.cpc-db-results-box');
	var $resultsList = $('#cpc-db-results-list');

	if (!confirm('Are you sure you want to run the administrative database maintenance sequence?')) {
		return;
	}

	$btn.hide();
	$loader.show();
	$resultsBox.hide();

	$.post(cpc_vars.ajax_url, {
		action: 'cpc_optimize_database',
		nonce: cpc_vars.nonce
	}, function(res) {
		$loader.hide();
		$btn.show();

		if (res.success) {
			$resultsList.empty();
			var stats = res.data.stats;

			$resultsList.append('<li><strong>Post Revisions:</strong> ' + stats.post_revisions + ' cleared</li>');
			$resultsList.append('<li><strong>Trashed Posts/Pages:</strong> ' + stats.trashed_posts + ' cleared</li>');
			$resultsList.append('<li><strong>Spammed Comments:</strong> ' + stats.spam_comments + ' cleared</li>');
			$resultsList.append('<li><strong>Trackbacks & Pingbacks:</strong> ' + stats.pingbacks_trackbacks + ' cleared</li>');
			$resultsList.append('<li><strong>Orphaned PostMeta:</strong> ' + stats.orphaned_postmeta + ' rows</li>');
			$resultsList.append('<li><strong>Orphaned UserMeta:</strong> ' + stats.orphaned_usermeta + ' rows</li>');
			$resultsList.append('<li><strong>Orphaned TermMeta:</strong> ' + stats.orphaned_termmeta + ' rows</li>');
			$resultsList.append('<li><strong>Expired Transients:</strong> ' + stats.transient_options + ' cleaned</li>');
			$resultsList.append('<li><strong>Database Table Engine:</strong> Tables optimized successfully</li>');

			$resultsBox.slideDown();
		} else {
			alert('Error: ' + res.data.message);
		}
	}).fail(function() {
		$loader.hide();
		$btn.show();
		alert('DB optimization AJAX failed.');
	});
}

/**
 * Trigger deep image optimization compression simulation.
 */
function CPC_Trigger_Optimize_Images() {
	var $ = jQuery;
	var $btn = $('.cpc-btn-optimize-images');
	var oldText = $btn.text();

	$btn.text('Compressing Image Assets Loop...').prop('disabled', true);

	$.post(cpc_vars.ajax_url, {
		action: 'cpc_optimize_all_images',
		nonce: cpc_vars.nonce
	}, function(res) {
		$btn.text(oldText).prop('disabled', false);

		if (res.success) {
			alert(res.data.message);

			// Animate Circular graph updates
			$('#cpc-circle-succeed-path').attr('stroke-dasharray', res.data.succeed + ', 100');
			$('#cpc-image-succeed-text').text(res.data.succeed + '%');

			// Update textual stats
			$('#cpc-meta-succeed').text(res.data.succeed + '%');
			$('#cpc-meta-pending').text(res.data.pending + ' assets');
			$('#cpc-meta-errors').text(res.data.errors + ' failed');

			// Update Recovered Size display
			$('#cpc-recovered-size-display').text(res.data.recovered);
		} else {
			alert('Error: ' + res.data.message);
		}
	}).fail(function() {
		$btn.text(oldText).prop('disabled', false);
		alert('Image optimization request failed.');
	});
}
