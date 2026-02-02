<?php
/**
 * Upload newly uploaded media files to CF R2.
 *
 * Hooks:
 * - wp_handle_upload (filter): upload file contents to R2 after WP writes it locally.
 * - add_attachment (action): persist attachment meta after attachment is created.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Per-request cache of offloaded objects keyed by local absolute file path.
 *
 * @var array<string, array{key:string}>
 */
static $r2mo_offload_cache = [];

/**
 * Compute the relative path inside the uploads directory for an absolute file path.
 *
 * @param string $absolute_file Absolute file path.
 * @return string Relative path inside uploads (e.g. 2026/01/image.jpg). Empty string on failure.
 */
function r2mo_get_uploads_relative_path(string $absolute_file): string {
	$uploads = wp_get_upload_dir();
	$basedir = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
	if ($basedir === '') {
		return '';
	}

	$basedir = wp_normalize_path($basedir);
	$file    = wp_normalize_path($absolute_file);

	if (str_starts_with($file, $basedir . '/')) {
		return ltrim(substr($file, strlen($basedir)), '/');
	}

	// Fallback: if we can't determine, avoid offloading (safer than guessing a key).
	return '';
}

/**
 * Build final R2 object key from relative path and optional path_prefix.
 *
 * @param string $relative_path Relative path inside uploads.
 * @return string Object key.
 */
function r2mo_build_object_key(string $relative_path): string {
	$relative_path = ltrim($relative_path, '/');
	$prefix        = (string) Settings::get('path_prefix');
	$prefix        = trim($prefix);
	$prefix        = trim($prefix, "/ \t\n\r\0\x0B");

	if ($relative_path === '') {
		return '';
	}

	if ($prefix === '') {
		return $relative_path;
	}

	return $prefix . '/' . $relative_path;
}

/**
 * Check whether R2 is configured enough to attempt uploads.
 */
function r2mo_r2_is_configured(): bool {
	$account_id = (string) Settings::get('account_id');
	$access_key = (string) Settings::get('access_key');
	$secret_key = (string) Settings::get('secret_key');
	$bucket     = (string) Settings::get('bucket');

	return $account_id !== '' && $access_key !== '' && $secret_key !== '' && $bucket !== '';
}

add_filter(
	'wp_handle_upload',
	static function (array $upload): array {
		// Always let WP proceed; this filter must never break uploads.
		if (! is_array($upload)) {
			return $upload;
		}

		$file = isset($upload['file']) ? (string) $upload['file'] : '';
		$type = isset($upload['type']) ? (string) $upload['type'] : '';

		if ($file === '' || ! file_exists($file) || ! is_readable($file)) {
			return $upload;
		}

		if (! r2mo_r2_is_configured()) {
			return $upload;
		}

		if (! r2mo_is_sdk_available()) {
			return $upload;
		}

		$relative = r2mo_get_uploads_relative_path($file);
		if ($relative === '') {
			return $upload;
		}

		$key = r2mo_build_object_key($relative);
		if ($key === '') {
			return $upload;
		}

		// Avoid duplicate uploads within a single request (e.g., intermediate sizes in same flow).
		// Note: This does not prevent duplicates across requests; that is intentional for safety/min overhead.
		global $r2mo_offload_cache;
		if (isset($r2mo_offload_cache[$file]['key']) && $r2mo_offload_cache[$file]['key'] === $key) {
			return $upload;
		}

		$bucket = (string) Settings::get('bucket');

		try {
			global $wp_filesystem;
			if (empty($wp_filesystem)) {
				require_once ABSPATH . '/wp-admin/includes/file.php';
				WP_Filesystem();
			}

			if (! $wp_filesystem || ! $wp_filesystem->exists($file)) {
				return $upload;
			}

			$body = $wp_filesystem->get_contents($file);
			if ($body === false) {
				return $upload;
			}

			$client = R2_Client::instance()->client();
			$client->putObject(
				[
					'Bucket'      => $bucket,
					'Key'         => $key,
					'ACL'         => 'public-read',
					'Body'        => $body,
					'ContentType' => $type !== '' ? $type : null,
				]
			);

			// Cache for later meta update once the attachment is created.
			$r2mo_offload_cache[$file] = [
				'key' => $key,
			];
		} catch (\Throwable $e) {
			// Fail gracefully: do not break upload flow.
			return $upload;
		}

		return $upload;
	},
	20,
	1
);

add_action(
	'add_attachment',
	static function (int $attachment_id): void {
		if ($attachment_id <= 0) {
			return;
		}

		// Avoid duplicate meta writes (and implicitly avoid duplicate uploads in future logic).
		$already = get_post_meta($attachment_id, '_r2_offloaded', true);
		if ($already) {
			return;
		}

		$file = get_attached_file($attachment_id);
		if (! is_string($file) || $file === '') {
			return;
		}

		global $r2mo_offload_cache;
		if (! isset($r2mo_offload_cache[$file]['key'])) {
			return;
		}

		$key = (string) $r2mo_offload_cache[$file]['key'];
		if ($key === '') {
			return;
		}

		update_post_meta($attachment_id, '_r2_offloaded', true);
		update_post_meta($attachment_id, '_r2_key', $key);
		update_post_meta($attachment_id, '_r2_object_key', $key);

		$public_url = r2mo_public_url_for_key($key);
		if ($public_url !== '') {
			update_post_meta($attachment_id, '_r2_public_url', $public_url);
		}
	},
	20,
	1
);

