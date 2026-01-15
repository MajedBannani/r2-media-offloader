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


