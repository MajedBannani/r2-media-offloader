<?php
/**
 * CF R2 client (S3 compatible).
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

final class R2_Client {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Underlying AWS S3 client instance.
	 *
	 * @var object|null
	 */
	private $client = null;

	/**
	 * Get singleton instance.
	 */
	public static function instance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get (or build) the S3Client.
	 *
	 * Returns a singleton S3Client instance configured for CF R2.
	 * The client is built using credentials from the plugin settings and
	 * configured with the appropriate endpoint and region.
	 *
	 * @since 1.0.0
	 * @return object AWS SDK S3Client instance configured for CF R2.
	 */
	public function client() {
		if (! r2mo_is_sdk_available()) {
			throw new \RuntimeException(r2mo_sdk_missing_message());
		}

		if ($this->client instanceof \Aws\S3\S3Client) {
			return $this->client;
		}

		$account_id = (string) Settings::get('account_id');
		$access_key = (string) Settings::get('access_key');
		$secret_key = (string) Settings::get('secret_key');

		$endpoint = sprintf('https://%s.r2.cloudflarestorage.com', $account_id);

		$client = new \Aws\S3\S3Client(
			[
				'version'                 => 'latest',
				'region'                  => 'auto',
				'endpoint'                => $endpoint,
				'credentials'             => [
					'key'    => $access_key,
					'secret' => $secret_key,
				],
				'use_path_style_endpoint' => true,
			]
		);

		$this->client = $client;
		return $client;
	}

	/**
	 * Test credentials + connectivity.
	 *
	 * Verifies that the configured CF R2 credentials are valid and
	 * that the bucket is accessible. This is used by the admin settings page
	 * to allow users to test their configuration.
	 *
	 * @since 1.0.0
	 * @return true|string Returns true on success, otherwise an error message string.
	 */
	public function test_connection() {
		$account_id = (string) Settings::get('account_id');
		$access_key = (string) Settings::get('access_key');
		$secret_key = (string) Settings::get('secret_key');
		$bucket     = (string) Settings::get('bucket');

		if ($account_id === '' || $access_key === '' || $secret_key === '') {
			return __('Missing R2 credentials (account_id / access_key / secret_key).', 'media-offloader-for-cf-r2');
		}

		if ($bucket === '') {
			return __('Missing R2 bucket.', 'media-offloader-for-cf-r2');
		}

		try {
			$this->client()->headBucket(['Bucket' => $bucket]);
			return true;
		} catch (\Throwable $e) {
			return r2mo_get_aws_error_message($e);
		}
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup(): void {
		// Intentionally empty.
	}
}


