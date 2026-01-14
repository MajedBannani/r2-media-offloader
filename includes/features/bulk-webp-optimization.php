<?php
/**
 * Bulk WebP optimization for existing media attachments.
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
 * Check if an attachment should be optimized to WebP.
 *
 * @param int $attachment_id Attachment ID.
 * @return bool
 */
function r2mo_should_optimize_attachment(int $attachment_id): bool {
	if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
		return false;
	}

	$mime_type = get_post_mime_type($attachment_id);
	if (! is_string($mime_type)) {
		return false;
	}

	// Only optimize jpeg and png images.
	$supported = ['image/jpeg', 'image/jpg', 'image/png'];
	if (! in_array(strtolower($mime_type), $supported, true)) {
		return false;
	}

	// Skip if already has WebP version.
	$webp_key = get_post_meta($attachment_id, '_r2_webp_key', true);
	if ($webp_key) {
		return false;
	}

	// Check if local file exists.
	$file = get_attached_file($attachment_id);
	if (! is_string($file) || $file === '' || ! file_exists($file)) {
		return false;
	}

	return true;
}

/**
 * Optimize a single attachment to WebP and upload to R2.
 *
 * @param int $attachment_id Attachment ID.
 * @return array{status:string,message:string,webp_key:string}
 */
function r2mo_optimize_attachment_to_webp(int $attachment_id): array {
	try {
		if (! r2mo_should_optimize_attachment($attachment_id)) {
			return [
				'status'   => 'skipped',
				'message'  => 'Attachment does not need optimization.',
				'webp_key' => '',
			];
		}

		$file = get_attached_file($attachment_id);
		if (! is_string($file) || $file === '' || ! file_exists($file)) {
			return [
				'status'   => 'failed',
				'message'  => 'Local file not found.',
				'webp_key' => '',
			];
		}

		// Check if R2 is configured.
		if (! r2mo_r2_is_configured()) {
			return [
				'status'   => 'failed',
				'message'  => 'R2 not configured.',
				'webp_key' => '',
			];
		}

		// Get image editor.
		$editor = wp_get_image_editor($file);
		if (is_wp_error($editor)) {
			return [
				'status'   => 'failed',
				'message'  => $editor->get_error_message(),
				'webp_key' => '',
			];
		}

		// Check WebP support.
		$supports = $editor->supports_mime_type('image/webp');
		if (! $supports) {
			return [
				'status'   => 'failed',
				'message'  => 'Image editor does not support WebP.',
				'webp_key' => '',
			];
		}

		// Generate WebP path (alongside original).
		$webp_path = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $file);
		if (! is_string($webp_path) || $webp_path === $file) {
			return [
				'status'   => 'failed',
				'message'  => 'Failed to generate WebP file path.',
				'webp_key' => '',
			];
		}

		// Skip if WebP already exists locally.
		if (file_exists($webp_path)) {
			// Check if it's already uploaded to R2.
			$existing_key = get_post_meta($attachment_id, '_r2_webp_key', true);
			if ($existing_key) {
				return [
					'status'   => 'skipped',
					'message'  => 'WebP version already exists.',
					'webp_key' => (string) $existing_key,
				];
			}
		}

		// Load image.
		$loaded = $editor->load();
		if (is_wp_error($loaded)) {
			return [
				'status'   => 'failed',
				'message'  => $loaded->get_error_message(),
				'webp_key' => '',
			];
		}

		// Get dimensions.
		$size = $editor->get_size();
		if (! isset($size['width']) || ! isset($size['height'])) {
			return [
				'status'   => 'failed',
				'message'  => 'Failed to get image dimensions.',
				'webp_key' => '',
			];
		}

		// Resize to maintain dimensions.
		$resized = $editor->resize($size['width'], $size['height'], false);
		if (is_wp_error($resized)) {
			return [
				'status'   => 'failed',
				'message'  => $resized->get_error_message(),
				'webp_key' => '',
			];
		}

		// Save as WebP.
		$saved = $editor->save($webp_path, 'image/webp');
		if (is_wp_error($saved)) {
			return [
				'status'   => 'failed',
				'message'  => $saved->get_error_message(),
				'webp_key' => '',
			];
		}

		if (! isset($saved['path']) || ! file_exists($saved['path'])) {
			return [
				'status'   => 'failed',
				'message'  => 'WebP file was not created.',
				'webp_key' => '',
			];
		}

		$final_webp_path = (string) $saved['path'];

		// Upload WebP to R2.
		$relative = r2mo_get_uploads_relative_path($final_webp_path);
		if ($relative === '') {
			wp_delete_file($final_webp_path);
			return [
				'status'   => 'failed',
				'message'  => 'Failed to determine uploads relative path.',
				'webp_key' => '',
			];
		}

		$key = r2mo_build_object_key($relative);
		if ($key === '') {
			wp_delete_file($final_webp_path);
			return [
				'status'   => 'failed',
				'message'  => 'Failed to build R2 object key.',
				'webp_key' => '',
			];
		}

		$bucket = (string) Settings::get('bucket');

		try {
			global $wp_filesystem;
			if (empty($wp_filesystem)) {
				require_once ABSPATH . '/wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if (! $wp_filesystem || ! $wp_filesystem->exists($final_webp_path)) {
				wp_delete_file($final_webp_path);
				return [
					'status'   => 'failed',
					'message'  => 'WebP file not found for upload.',
					'webp_key' => '',
				];
			}

			$body = $wp_filesystem->get_contents($final_webp_path);
			if ($body === false) {
				wp_delete_file($final_webp_path);
				return [
					'status'   => 'failed',
					'message'  => 'Failed to read WebP file for upload.',
					'webp_key' => '',
				];
			}

			$client = R2_Client::instance()->client();
			$client->putObject(
				[
					'Bucket'      => $bucket,
					'Key'         => $key,
					'ACL'         => 'public-read',
					'Body'        => $body,
					'ContentType' => 'image/webp',
				]
			);

			// Store WebP key in attachment meta.
			update_post_meta($attachment_id, '_r2_webp_key', $key);

			return [
				'status'   => 'optimized',
				'message'  => 'Successfully optimized and uploaded to R2.',
				'webp_key' => $key,
			];
		} catch (AwsException $e) {
			wp_delete_file($final_webp_path);
			$msg = $e->getAwsErrorMessage() ?: $e->getMessage();
			return [
				'status'   => 'failed',
				'message'  => 'R2 upload failed: ' . $msg,
				'webp_key' => '',
			];
		} catch (\Throwable $e) {
			wp_delete_file($final_webp_path);
			return [
				'status'   => 'failed',
				'message'  => 'Unexpected error: ' . $e->getMessage(),
				'webp_key' => '',
			];
		}
	} catch (\Throwable $e) {
		return [
			'status'   => 'failed',
			'message'  => 'Unexpected error during optimization.',
			'webp_key' => '',
		];
	}
}

/**
 * Process a batch of attachments for WebP optimization.
 *
 * @param int $limit Number of attachments to process per batch.
 * @return array{optimized:int,skipped:int,failed:int,total:int}
 */
function r2mo_optimize_webp_batch(int $limit = 20): array {
	$args = [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => $limit,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'post_mime_type' => ['image/jpeg', 'image/jpg', 'image/png'],
		// This meta_query is used intentionally for WP-CLI and batch processing.
		// It does not run on frontend requests, and performance impact is acceptable.
		'meta_query'     => [
			[
				'key'     => '_r2_webp_key',
				'compare' => 'NOT EXISTS',
			],
		],
	];

	$query = new \WP_Query($args);

	$optimized = 0;
	$skipped   = 0;
	$failed    = 0;
	$total     = (int) $query->found_posts;

	if (! empty($query->posts) && is_array($query->posts)) {
		foreach ($query->posts as $attachment_id) {
			try {
				$result = r2mo_optimize_attachment_to_webp((int) $attachment_id);

				switch ($result['status']) {
					case 'optimized':
						$optimized++;
						break;
					case 'failed':
						$failed++;
						break;
					default:
						$skipped++;
						break;
				}
			} catch (\Throwable $e) {
				$failed++;
			}
		}
	}

	return [
		'optimized' => $optimized,
		'skipped'   => $skipped,
		'failed'    => $failed,
		'total'     => $total,
	];
}
