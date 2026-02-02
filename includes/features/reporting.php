<?php
/**
 * Reporting utilities for R2 sync status.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Generate a sync report with counts only.
 *
 * @param callable|null $on_step Optional callback for progress reporting.
 * @return array<string, mixed>
 */
function r2mo_generate_sync_report(?callable $on_step = null): array {
	$report = [
		'generated_at'     => time(),
		'total_attachments'=> 0,
		'total_offloaded'  => 0,
		'total_r2_objects' => 0,
		'in_sync'          => 0,
		'missing_in_r2'    => 0,
		'orphaned_in_r2'   => 0,
		'errors'           => [],
	];

	$report['total_attachments'] = r2mo_get_total_attachment_count();
	$report['total_offloaded']   = r2mo_get_offloaded_attachment_count();
	if (is_callable($on_step)) {
		$on_step();
	}

	$attachment_keys = r2mo_get_offloaded_keys();
	$attachment_key_set = array_fill_keys($attachment_keys, true);
	if (is_callable($on_step)) {
		$on_step();
	}

	if (! r2mo_is_sdk_available()) {
		$report['errors'][] = r2mo_sdk_missing_message();
		$report['missing_in_r2'] = max(0, $report['total_attachments'] - $report['in_sync']);
		return $report;
	}

	$bucket = (string) Settings::get('bucket');
	if ($bucket === '') {
		$report['errors'][] = __('R2 bucket not configured.', 'media-offloader-for-cf-r2');
		$report['missing_in_r2'] = max(0, $report['total_attachments'] - $report['in_sync']);
		return $report;
	}

	$r2_list = r2mo_list_r2_objects();
	if ($r2_list['error'] !== '') {
		$report['errors'][] = $r2_list['error'];
		$report['missing_in_r2'] = max(0, $report['total_attachments'] - $report['in_sync']);
		return $report;
	}

	$report['total_r2_objects'] = (int) $r2_list['total'];

	$in_sync = 0;
	$orphaned = 0;

	foreach ($r2_list['keys'] as $key) {
		if (isset($attachment_key_set[$key])) {
			$in_sync++;
		} else {
			$orphaned++;
		}
	}
	if (is_callable($on_step)) {
		$on_step();
	}

	$report['in_sync']        = $in_sync;
	$report['orphaned_in_r2'] = $orphaned;
	$report['missing_in_r2']  = max(0, $report['total_attachments'] - $in_sync);

	return $report;
}

/**
 * Count total attachments in the media library.
 */
function r2mo_get_total_attachment_count(): int {
	$counts = wp_count_posts('attachment');
	if (! is_object($counts) || ! isset($counts->inherit)) {
		return 0;
	}

	return (int) $counts->inherit;
}

/**
 * Count attachments marked as offloaded.
 */
function r2mo_get_offloaded_attachment_count(): int {
	global $wpdb;

	$query = $wpdb->prepare(
		"SELECT COUNT(DISTINCT pm.post_id)
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value IN (%s, %s)",
		'attachment',
		'_r2_offloaded',
		'1',
		'true'
	);

	return (int) $wpdb->get_var($query);
}

/**
 * Fetch all offloaded object keys from attachment meta.
 *
 * @return array<string>
 */
function r2mo_get_offloaded_keys(): array {
	global $wpdb;

	$query = $wpdb->prepare(
		"SELECT pm.meta_value
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value <> ''",
		'attachment',
		'_r2_key'
	);

	$keys = $wpdb->get_col($query);
	if (! is_array($keys)) {
		return [];
	}

	$keys = array_filter($keys, 'is_string');
	$keys = array_values(array_unique($keys));
	return $keys;
}
