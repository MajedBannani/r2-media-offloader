<?php
/**
 * Uninstall cleanup for Media Offloader for CF R2.
 *
 * @package R2MO
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Perform cleanup for a single site.
 */
function r2mo_uninstall_cleanup_site(): void {
	global $wpdb;

	// Remove plugin options.
	$option_keys = [
		'r2mo_settings',
	];

	foreach ($option_keys as $option_key) {
		delete_option($option_key);
		delete_site_option($option_key);
		wp_cache_delete($option_key, 'options');
	}

	// Remove plugin transients.
	$transient_keys = [
		'r2mo_github_release',
		'r2mo_github_release_notice',
		'r2mo_sync_report',
	];

	foreach ($transient_keys as $transient_key) {
		delete_transient($transient_key);
		delete_site_transient($transient_key);
		wp_cache_delete($transient_key);
	}

	// Clear scheduled hooks if any were added in the future.
	$cron_hooks = [];
	foreach ($cron_hooks as $hook) {
		wp_clear_scheduled_hook($hook);
	}

	// Remove attachment meta created by this plugin (do not delete media files).
	$meta_keys = [
		'_r2_offloaded',
		'_r2_key',
		'_r2_local_deleted',
	];

	foreach ($meta_keys as $meta_key) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE pm FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND pm.meta_key = %s",
				'attachment',
				$meta_key
			)
		);
	}

	// Best-effort cache cleanup for object cache groups used by the plugin.
	wp_cache_delete('r2mo_posts_', 'media-offloader-for-cf-r2');
	wp_cache_delete('r2mo_post_content_', 'media-offloader-for-cf-r2');
	wp_cache_delete('r2mo_options_', 'media-offloader-for-cf-r2');
}

if (is_multisite()) {
	$sites = get_sites(['fields' => 'ids']);
	foreach ($sites as $site_id) {
		switch_to_blog((int) $site_id);
		r2mo_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	r2mo_uninstall_cleanup_site();
}
