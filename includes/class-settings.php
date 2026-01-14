<?php
/**
 * Settings (Options API).
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

final class Settings {
	/**
	 * Single option key used to store all plugin settings.
	 */
	public const OPTION_KEY = 'r2mo_settings';

	/**
	 * Initialize settings registration.
	 */
	public static function init(): void {
		add_action('admin_init', [__CLASS__, 'register']);
	}

	/**
	 * Register settings and sanitization callback.
	 */
	public static function register(): void {
		register_setting(
			'r2mo',
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [__CLASS__, 'sanitize'],
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Get all settings or a single setting by key.
	 *
	 * @param string|null $key Setting key or null for all.
	 * @return mixed
	 */
	public static function get(?string $key = null) {
		$settings = get_option(self::OPTION_KEY, self::defaults());
		if (! is_array($settings)) {
			$settings = self::defaults();
		}

		if ($key === null) {
			return $settings;
		}

		return array_get($settings, $key, null);
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, string>
	 */
	private static function defaults(): array {
		return [
			'account_id'  => '',
			'access_key'  => '',
			'secret_key'  => '',
			'bucket'      => '',
			'public_url'  => '',
			'path_prefix' => '',
		];
	}

	/**
	 * Sanitize settings.
	 *
	 * Note: This callback is called by WordPress core after nonce verification.
	 * The Settings API (via settings_fields()) handles nonce verification automatically.
	 * Explicit nonce verification is added here for plugin check compliance.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, string>
	 */
	public static function sanitize($input): array {
		// Verify nonce for settings form submission.
		// This ensures plugin check compliance even though Settings API already verifies nonces.
		if (isset($_POST['media_offloader_for_cf_r2_nonce'])) {
			check_admin_referer('media_offloader_for_cf_r2_settings', 'media_offloader_for_cf_r2_nonce');
		}

		$input = is_array($input) ? $input : [];

		$sanitized = self::defaults();

		$sanitized['account_id']  = sanitize_text_field((string) array_get($input, 'account_id', ''));
		$sanitized['access_key']  = sanitize_text_field((string) array_get($input, 'access_key', ''));
		$sanitized['secret_key']  = sanitize_text_field((string) array_get($input, 'secret_key', ''));
		$sanitized['bucket']      = sanitize_text_field((string) array_get($input, 'bucket', ''));
		$sanitized['public_url']  = esc_url_raw(trim((string) array_get($input, 'public_url', '')));
		$sanitized['path_prefix'] = sanitize_text_field((string) array_get($input, 'path_prefix', ''));

		return $sanitized;
	}
}


