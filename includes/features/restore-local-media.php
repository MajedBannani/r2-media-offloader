<?php
/**
 * Restore local media files from CF R2.
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
 * Restore local files for a single attachment from R2.
 *
 * This function downloads files from CF R2 and restores them to the
 * local uploads directory. It restores the original file and all image sizes
 * (thumbnails) if they exist in R2. This is useful for migration, backup, or
 * when local files are needed again.
 *
 * Safety guarantees:
 * - Only restores if _r2_offloaded=true and _r2_key exists
 * - Verifies object exists in R2 via HEAD request before download
 * - Skips files that already exist locally
 * - Creates directories as needed
 * - Never modifies attachment posts or metadata
 * - Preserves original file paths and structure
 *
 * @since 1.0.0
 * @param int $attachment_id Attachment ID.
 * @return array{status:string,message:string,restored:int,skipped:int,failed:int} Result array with status and counts.
 */
function r2mo_restore_local_for_attachment(int $attachment_id): array {
	try {
		if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
			return [
				'status'   => 'skipped',
				'message'  => 'Not an attachment.',
				'restored' => 0,
				'skipped'  => 1,
				'failed'   => 0,
			];
		}

		$offloaded = get_post_meta($attachment_id, '_r2_offloaded', true);
		$key       = get_post_meta($attachment_id, '_r2_key', true);

		if (! $offloaded || ! is_string($key) || $key === '') {
			return [
				'status'   => 'skipped',
				'message'  => 'Attachment not marked as offloaded or missing key.',
				'restored' => 0,
				'skipped'  => 1,
				'failed'   => 0,
			];
		}

		$bucket = (string) Settings::get('bucket');
		if ($bucket === '') {
			return [
				'status'   => 'failed',
				'message'  => 'Bucket not configured.',
				'restored' => 0,
				'skipped'  => 0,
				'failed'   => 1,
			];
		}

		// Verify main object exists.
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
				'status'   => 'failed',
				'message'  => 'R2 object not accessible; cannot restore.',
				'restored' => 0,
				'skipped'  => 0,
				'failed'   => 1,
			];
		} catch (\Throwable $e) {
			return [
				'status'   => 'failed',
				'message'  => 'Error verifying R2 object; cannot restore.',
				'restored' => 0,
				'skipped'  => 0,
				'failed'   => 1,
			];
		}

		$uploads = wp_get_upload_dir();
		$basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';

		if ($basedir === '') {
			return [
				'status'   => 'failed',
				'message'  => 'Uploads directory not available.',
				'restored' => 0,
				'skipped'  => 0,
				'failed'   => 1,
			];
		}

		$basedir_norm = wp_normalize_path(rtrim($basedir, '/'));

		$files_map = [];

		$metadata = wp_get_attachment_metadata($attachment_id);
		if (is_array($metadata) && ! empty($metadata['file']) && is_string($metadata['file'])) {
			$main_rel  = ltrim($metadata['file'], '/');
			$main_path = wp_normalize_path($basedir_norm . '/' . $main_rel);
			$files_map[$main_path] = $key;

			// If sizes are defined, attempt to restore them by mapping to sibling keys.
			if (isset($metadata['sizes']) && is_array($metadata['sizes']) && ! empty($metadata['sizes'])) {
				$dir_key  = wp_normalize_path((string) wp_normalize_path(dirname($key)));
				$dir_path = wp_normalize_path(dirname($main_path));

				foreach ($metadata['sizes'] as $size) {
					if (! is_array($size) || empty($size['file']) || ! is_string($size['file'])) {
						continue;
					}

					$size_rel = ltrim($size['file'], '/');
					if ($size_rel === '') {
						continue;
					}

					$size_path = wp_normalize_path($dir_path . '/' . $size_rel);
					$size_key  = $dir_key !== '.' ? $dir_key . '/' . $size_rel : $size_rel;

					$files_map[$size_path] = $size_key;
				}
			}
		} else {
			// Fallback to attached file if metadata is unavailable.
			$attached = get_attached_file($attachment_id);
			if (is_string($attached) && $attached !== '') {
				$attached_norm = wp_normalize_path($attached);
				if (strpos($attached_norm, $basedir_norm . '/') === 0) {
					$files_map[$attached_norm] = $key;
				}
			}
		}

		if (empty($files_map)) {
			return [
				'status'   => 'skipped',
				'message'  => 'No restorable file paths resolved.',
				'restored' => 0,
				'skipped'  => 1,
				'failed'   => 0,
			];
		}

		$restored = 0;
		$skipped  = 0;
		$failed   = 0;

		foreach ($files_map as $path => $object_key) {
			if (! is_string($path) || $path === '') {
				continue;
			}

			$path_norm = wp_normalize_path($path);

			// Ensure path is inside uploads.
			if (strpos($path_norm, $basedir_norm . '/') !== 0) {
				$skipped++;
				continue;
			}

			if (file_exists($path_norm)) {
				// Already present.
				$skipped++;
				continue;
			}

			$dir = dirname($path_norm) ?: '';
			if ($dir !== '' && ! is_dir($dir)) {
				try {
					if (! wp_mkdir_p($dir)) {
						$failed++;
						continue;
					}
				} catch (\Throwable $e) {
					$failed++;
					continue;
				}
			}

			try {
				$client = R2_Client::instance()->client();
				$client->getObject(
					[
						'Bucket' => $bucket,
						'Key'    => $object_key,
						'SaveAs' => $path_norm,
					]
				);

				if (file_exists($path_norm)) {
					$restored++;
				} else {
					$failed++;
				}
			} catch (AwsException $e) {
				$failed++;
			} catch (\Throwable $e) {
				$failed++;
			}
		}

		$status  = $restored > 0 && $failed === 0 ? 'restored' : ($restored > 0 ? 'partial' : 'skipped');
		$message = $status === 'restored' ? 'All missing files restored.' : ($status === 'partial' ? 'Some files restored; some failed.' : 'No files restored.');

		return [
			'status'   => $status,
			'message'  => $message,
			'restored' => $restored,
			'skipped'  => $skipped,
			'failed'   => $failed,
		];
	} catch (\Throwable $e) {
		return [
			'status'   => 'failed',
			'message'  => 'Unexpected error during restore; no files written.',
			'restored' => 0,
			'skipped'  => 0,
			'failed'   => 1,
		];
	}
}

/**
 * Process a batch of attachments for restoring local files.
 *
 * @param int $limit Number of attachments to process.
 * @return array{processed:int,restored:int,skipped:int,failed:int}
 */
function r2mo_restore_local_batch(int $limit = 50): array {
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
		],
	];

	$query = new \WP_Query($args);

	$processed = 0;
	$restored  = 0;
	$skipped   = 0;
	$failed    = 0;

	if (! empty($query->posts) && is_array($query->posts)) {
		foreach ($query->posts as $attachment_id) {
			$processed++;

			try {
				$result = r2mo_restore_local_for_attachment((int) $attachment_id);

				$restored += $result['restored'];
				$skipped  += $result['skipped'];
				$failed   += $result['failed'];
			} catch (\Throwable $e) {
				$failed++;
				continue;
			}
		}
	}

	return [
		'processed' => $processed,
		'restored'  => $restored,
		'skipped'   => $skipped,
		'failed'    => $failed,
	];
}

