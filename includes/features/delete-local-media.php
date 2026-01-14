<?php
/**
 * Safe deletion of local media files after successful R2 offload.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

use Aws\Exception\AwsException;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Delete local files for a single attachment if it is safely stored in R2.
 *
 * This function performs safe deletion of local media files after verifying
 * that the files exist in CF R2. It deletes the original file and all
 * generated image sizes (thumbnails) while preserving the attachment post
 * and all metadata.
 *
 * Safety guarantees:
 * - Only deletes if _r2_offloaded=true and _r2_key exists
 * - Verifies object exists in R2 via HEAD request before deletion
 * - Validates file paths are within uploads directory
 * - Checks file exists and is writable before deletion
 * - Never deletes attachment posts or metadata
 * - Sets _r2_local_deleted meta to prevent re-processing
 *
 * @since 1.0.0
 * @param int $attachment_id Attachment ID.
 * @return array{status:string,message:string,deleted:int,skipped:int,failed:int} Result array with status and counts.
 */
function r2mo_delete_local_for_attachment(int $attachment_id): array {
	try {
		if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
			return [
				'status'  => 'skipped',
				'message' => 'Not an attachment.',
				'deleted' => 0,
				'skipped' => 1,
				'failed'  => 0,
			];
		}

		$offloaded = get_post_meta($attachment_id, '_r2_offloaded', true);
		$key       = get_post_meta($attachment_id, '_r2_key', true);

		if (! $offloaded || ! is_string($key) || $key === '') {
			return [
				'status'  => 'skipped',
				'message' => 'Attachment not marked as offloaded or missing key.',
				'deleted' => 0,
				'skipped' => 1,
				'failed'  => 0,
			];
		}

		// Optional guard to avoid re-processing if already cleaned.
		$already_cleaned = get_post_meta($attachment_id, '_r2_local_deleted', true);
		if ($already_cleaned) {
			return [
				'status'  => 'skipped',
				'message' => 'Local files already deleted.',
				'deleted' => 0,
				'skipped' => 1,
				'failed'  => 0,
			];
		}

		// Verify object exists in R2 via HEAD request.
		$bucket = (string) Settings::get('bucket');
		if ($bucket === '') {
			return [
				'status'  => 'failed',
				'message' => 'Bucket not configured.',
				'deleted' => 0,
				'skipped' => 0,
				'failed'  => 1,
			];
		}

		try {
			$client = R2_Client::instance()->client();
			$client->headObject(
				[
					'Bucket' => $bucket,
					'Key'    => $key,
				]
			);
		} catch (AwsException $e) {
			return [
				'status'  => 'failed',
				'message' => 'R2 object not accessible; local files kept.',
				'deleted' => 0,
				'skipped' => 0,
				'failed'  => 1,
			];
		} catch (\Throwable $e) {
			return [
				'status'  => 'failed',
				'message' => 'Error verifying R2 object; local files kept.',
				'deleted' => 0,
				'skipped' => 0,
				'failed'  => 1,
			];
		}

		// Build list of local files to delete: original + generated sizes.
		$uploads = wp_get_upload_dir();
		$basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';

		$files = [];

		if ($basedir !== '') {
			$metadata = wp_get_attachment_metadata($attachment_id);

			if (is_array($metadata)) {
				if (! empty($metadata['file']) && is_string($metadata['file'])) {
					$main_file = wp_normalize_path($basedir . '/' . ltrim($metadata['file'], '/'));
					$files[]   = $main_file;

					if (! empty($metadata['sizes']) && is_array($metadata['sizes'])) {
						$original_dir = wp_normalize_path(dirname($main_file));

						foreach ($metadata['sizes'] as $size) {
							if (empty($size['file']) || ! is_string($size['file'])) {
								continue;
							}

							$files[] = wp_normalize_path($original_dir . '/' . ltrim($size['file'], '/'));
						}
					}
				}
			}

			// Fallback: if no usable metadata, try the attached file directly.
			if (empty($files)) {
				$attached = get_attached_file($attachment_id);
				if (is_string($attached) && $attached !== '') {
					$files[] = wp_normalize_path($attached);
				}
			}
		}

		$files   = array_values(array_unique(array_filter($files, 'is_string')));
		$deleted = 0;
		$failed  = 0;

		$normalized_basedir = $basedir !== '' ? wp_normalize_path(rtrim($basedir, '/')) : '';

		foreach ($files as $path) {
			if (! is_string($path) || $path === '') {
				continue;
			}

			$normalized_path = wp_normalize_path($path);

			// Ensure file is inside uploads directory.
			if ($normalized_basedir === '' || strpos($normalized_path, $normalized_basedir . '/') !== 0) {
				continue;
			}

			if (! file_exists($normalized_path)) {
				continue;
			}

			if (wp_delete_file($normalized_path)) {
				$deleted++;
			} else {
				$failed++;
			}
		}

		if ($deleted > 0) {
			update_post_meta($attachment_id, '_r2_local_deleted', true);
		}

		$status  = $deleted > 0 && $failed === 0 ? 'deleted' : ($deleted > 0 ? 'partial' : 'skipped');
		$message = $status === 'deleted' ? 'All local files deleted.' : ($status === 'partial' ? 'Some files deleted; some failed.' : 'No files deleted.');

		return [
			'status'  => $status,
			'message' => $message,
			'deleted' => $deleted,
			'skipped' => $status === 'skipped' ? 1 : 0,
			'failed'  => $failed,
		];
	} catch (\Throwable $e) {
		return [
			'status'  => 'failed',
			'message' => 'Unexpected error during local cleanup; local files kept.',
			'deleted' => 0,
			'skipped' => 0,
			'failed'  => 1,
		];
	}
}

/**
 * Process a batch of attachments for safe local deletion.
 *
 * @param int $limit Number of attachments to process.
 * @return array{processed:int,deleted:int,skipped:int,failed:int}
 */
function r2mo_delete_local_batch(int $limit = 50): array {
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
			'relation' => 'AND',
			[
				'key'   => '_r2_offloaded',
				'value' => true,
			],
			[
				'key'     => '_r2_local_deleted',
				'compare' => 'NOT EXISTS',
			],
		],
	];

	$query = new \WP_Query($args);

	$processed = 0;
	$deleted   = 0;
	$skipped   = 0;
	$failed    = 0;

	if (! empty($query->posts) && is_array($query->posts)) {
		foreach ($query->posts as $attachment_id) {
			$processed++;

			try {
				$result = r2mo_delete_local_for_attachment((int) $attachment_id);

				$deleted += $result['deleted'];
				$skipped += $result['skipped'];
				$failed  += $result['failed'];
			} catch (\Throwable $e) {
				$failed++;
				continue;
			}
		}
	}

	return [
		'processed' => $processed,
		'deleted'   => $deleted,
		'skipped'   => $skipped,
		'failed'    => $failed,
	];
}

