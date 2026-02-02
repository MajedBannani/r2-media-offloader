<?php
/**
 * Helper functions.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Safe array getter.
 *
 * @param array<string, mixed> $array Array to read.
 * @param string              $key   Key to read.
 * @param mixed               $default Default value.
 * @return mixed
 */
function array_get(array $array, string $key, $default = null) {
	return array_key_exists($key, $array) ? $array[$key] : $default;
}

/**
 * Check whether the AWS SDK classes are available.
 *
 * @return bool
 */
function r2mo_is_sdk_available(): bool {
	return class_exists('Aws\\S3\\S3Client') && class_exists('Aws\\Exception\\AwsException');
}

/**
 * Standard SDK missing message for admin/CLI output.
 *
 * @return string
 */
function r2mo_sdk_missing_message(): string {
	return __(
		'The Cloudflare R2 SDK is not available. Please install the plugin package that includes the SDK to enable R2 features.',
		'media-offloader-for-cf-r2'
	);
}

/**
 * Extract an AWS-specific message when available.
 *
 * @param \Throwable $error Error/exception to inspect.
 * @return string
 */
function r2mo_get_aws_error_message(\Throwable $error): string {
	if (method_exists($error, 'getAwsErrorMessage')) {
		$message = $error->getAwsErrorMessage();
		if (is_string($message) && $message !== '') {
			return $message;
		}
	}

	$message = $error->getMessage();
	return $message !== '' ? $message : __('Unknown error while communicating with R2.', 'media-offloader-for-cf-r2');
}

/**
 * Detect if an R2 error indicates a missing object.
 *
 * @param \Throwable $error Error/exception to inspect.
 * @return bool
 */
function r2mo_is_missing_object_error(\Throwable $error): bool {
	$code = (int) $error->getCode();
	if ($code === 404) {
		return true;
	}

	$message = strtolower(r2mo_get_aws_error_message($error));
	return str_contains($message, 'not found')
		|| str_contains($message, 'nosuchkey')
		|| str_contains($message, '404');
}

/**
 * Clear offload-related meta for a single attachment.
 *
 * @param int $attachment_id Attachment ID.
 */
function r2mo_clear_offload_meta_for_attachment(int $attachment_id): void {
	if ($attachment_id <= 0) {
		return;
	}

	$meta_keys = [
		'_r2_offloaded',
		'_r2_key',
		'_r2_object_key',
		'_r2_etag',
		'_r2_public_url',
		'_r2_local_deleted',
	];

	foreach ($meta_keys as $meta_key) {
		delete_post_meta($attachment_id, $meta_key);
	}
}

/**
 * Clear offload-related meta for attachments matching object keys.
 *
 * @param array<string> $keys R2 object keys.
 */
function r2mo_clear_offload_meta_for_keys(array $keys): void {
	global $wpdb;

	$keys = array_values(array_filter($keys, 'is_string'));
	if (empty($keys)) {
		return;
	}

	$placeholders = implode(',', array_fill(0, count($keys), '%s'));
	$params = array_merge(['attachment', '_r2_key'], $keys);

	$query = $wpdb->prepare(
		"SELECT pm.post_id FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value IN ($placeholders)",
		$params
	);

	$post_ids = $wpdb->get_col($query);
	if (! is_array($post_ids) || empty($post_ids)) {
		return;
	}

	foreach ($post_ids as $post_id) {
		r2mo_clear_offload_meta_for_attachment((int) $post_id);
	}
}


