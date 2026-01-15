<?php
/**
 * Sync existing media attachments to CF R2.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

use R2MO\Services\Url_Rewriter;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Offload a single attachment to R2.
 *
 * @param int $attachment_id Attachment post ID.
 * @return array{status:string,message:string,key:string}
 */
function r2mo_offload_attachment_to_r2(int $attachment_id): array {
	$status  = 'skipped';
	$message = '';
	$key     = '';

	if (! r2mo_is_sdk_available()) {
		return [
			'status'  => 'failed',
			'message' => r2mo_sdk_missing_message(),
			'key'     => '',
		];
	}

	if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
		return [
			'status'  => 'skipped',
			'message' => 'Not an attachment.',
			'key'     => '',
		];
	}

	$offloaded = get_post_meta($attachment_id, '_r2_offloaded', true);
	$existing  = get_post_meta($attachment_id, '_r2_key', true);
	if ($offloaded && is_string($existing) && $existing !== '') {
		return [
			'status'  => 'skipped',
			'message' => 'Already offloaded.',
			'key'     => (string) $existing,
		];
	}

	$file = get_attached_file($attachment_id);
	if (! is_string($file) || $file === '' || ! file_exists($file) || ! is_readable($file)) {
		return [
			'status'  => 'failed',
			'message' => 'Local file missing or not readable.',
			'key'     => '',
		];
	}

	$relative = r2mo_get_uploads_relative_path($file);
	if ($relative === '') {
		return [
			'status'  => 'failed',
			'message' => 'Could not determine uploads-relative path.',
			'key'     => '',
		];
	}

	$key = r2mo_build_object_key($relative);
	if ($key === '') {
		return [
			'status'  => 'failed',
			'message' => 'Could not build object key.',
			'key'     => '',
		];
	}

	$bucket = (string) Settings::get('bucket');
	if ($bucket === '') {
		return [
			'status'  => 'failed',
			'message' => 'Bucket not configured.',
			'key'     => '',
		];
	}

	$mime_type = get_post_mime_type($attachment_id) ?: '';

	try {
		global $wp_filesystem;
		if (empty($wp_filesystem)) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if (! $wp_filesystem || ! $wp_filesystem->exists($file)) {
			return [
				'status'  => 'failed',
				'message' => 'Could not open file for reading.',
				'key'     => '',
			];
		}

		$body = $wp_filesystem->get_contents($file);
		if ($body === false) {
			return [
				'status'  => 'failed',
				'message' => 'Could not read file contents.',
				'key'     => '',
			];
		}

		$client = R2_Client::instance()->client();
		$client->putObject(
			[
				'Bucket'      => $bucket,
				'Key'         => $key,
				'ACL'         => 'public-read',
				'Body'        => $body,
				'ContentType' => $mime_type !== '' ? $mime_type : null,
			]
		);

		update_post_meta($attachment_id, '_r2_offloaded', true);
		update_post_meta($attachment_id, '_r2_key', $key);

		$status  = 'processed';
		$message = 'Offloaded successfully.';

		// After a successful offload, rewrite stored URLs from local to CDN.
		$uploads   = wp_get_upload_dir();
		$baseurl   = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
		$local_url = $baseurl !== '' ? trailingslashit($baseurl) . ltrim($relative, '/') : '';

		$cdn_url = r2mo_public_url_for_key($key);

		if ($local_url !== '' && $cdn_url !== '' && $local_url !== $cdn_url) {
			Url_Rewriter::rewrite_for_attachment($attachment_id, $local_url, $cdn_url);
		}
	} catch (\Throwable $e) {
		$status  = 'failed';
		$message = r2mo_get_aws_error_message($e);
	}

	return [
		'status'  => $status,
		'message' => $message,
		'key'     => $status === 'processed' ? $key : '',
	];
}

/**
 * Process a batch of existing attachments that are not yet offloaded.
 *
 * @param int $limit Number of attachments to process in this batch.
 * @return array{processed:int,skipped:int,failed:int,total:int}
 */
function r2mo_sync_existing_batch(int $limit = 50): array {
	$args = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => $limit,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
		// This meta_query is used intentionally for WP-CLI and batch processing.
		// It does not run on frontend requests, and performance impact is acceptable.
		'meta_query'     => [
			'relation' => 'OR',
			[
				'key'     => '_r2_offloaded',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => '_r2_offloaded',
				'value'   => true,
				'compare' => '!=',
			],
		],
	];

	$query = new \WP_Query($args);

	$processed = 0;
	$skipped   = 0;
	$failed    = 0;

	if (! empty($query->posts) && is_array($query->posts)) {
		foreach ($query->posts as $attachment_id) {
			$result = r2mo_offload_attachment_to_r2((int) $attachment_id);
			switch ($result['status']) {
				case 'processed':
					$processed++;
					break;
				case 'failed':
					$failed++;
					break;
				default:
					$skipped++;
					break;
			}
		}
	}

	// Remaining count (rough estimate) for information purposes.
	$remaining_query = new \WP_Query(
		[
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => $args['meta_query'],
		]
	);

	$total_remaining = (int) $remaining_query->found_posts;

	return [
		'processed' => $processed,
		'skipped'   => $skipped,
		'failed'    => $failed,
		'total'     => $total_remaining,
	];
}

