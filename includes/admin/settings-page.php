<?php
/**
 * Admin settings page.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO\Admin;

use R2MO\R2_Client;
use R2MO\Settings;

if (! defined('ABSPATH')) {
	exit;
}

final class Settings_Page {
	public const MENU_SLUG = 'r2mo-settings';
	private const NOTICE_QS = 'r2mo_notice';
	private const NOTICE_MSG_QS = 'r2mo_notice_msg';
	private const SYNC_NOTICE_QS = 'r2mo_sync_notice';
	private const SYNC_PROCESSED_QS = 'r2mo_sync_processed';
	private const SYNC_SKIPPED_QS = 'r2mo_sync_skipped';
	private const SYNC_FAILED_QS = 'r2mo_sync_failed';
	private const CLEANUP_NOTICE_QS = 'r2mo_cleanup_notice';
	private const CLEANUP_DELETED_QS = 'r2mo_cleanup_deleted';
	private const CLEANUP_SKIPPED_QS = 'r2mo_cleanup_skipped';
	private const CLEANUP_FAILED_QS = 'r2mo_cleanup_failed';
	private const RESTORE_NOTICE_QS = 'r2mo_restore_notice';
	private const RESTORE_RESTORED_QS = 'r2mo_restore_restored';
	private const RESTORE_SKIPPED_QS = 'r2mo_restore_skipped';
	private const RESTORE_FAILED_QS = 'r2mo_restore_failed';
	private const PURGE_NOTICE_QS = 'r2mo_purge_notice';
	private const PURGE_MSG_QS = 'r2mo_purge_msg';
	private const WEBP_NOTICE_QS = 'r2mo_webp_notice';
	private const WEBP_OPTIMIZED_QS = 'r2mo_webp_optimized';
	private const WEBP_SKIPPED_QS = 'r2mo_webp_skipped';
	private const WEBP_FAILED_QS = 'r2mo_webp_failed';
	private const SETTINGS_GROUP = 'r2mo_settings_group';

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'register_menu']);
		add_action('admin_init', [__CLASS__, 'register_fields']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_styles']);
		add_action('admin_post_r2mo_test_connection', [__CLASS__, 'handle_test_connection']);
		add_action('admin_post_r2mo_sync_existing', [__CLASS__, 'handle_sync_existing']);
		add_action('admin_post_r2mo_delete_local', [__CLASS__, 'handle_delete_local']);
		add_action('admin_post_r2mo_restore_local', [__CLASS__, 'handle_restore_local']);
		add_action('admin_post_r2mo_purge_bucket', [__CLASS__, 'handle_purge_bucket']);
		add_action('admin_post_r2mo_optimize_webp', [__CLASS__, 'handle_optimize_webp']);

		// Preserve existing secret_key if password field is left blank (password input is intentionally empty on reload).
		add_filter('pre_update_option_' . Settings::OPTION_KEY, [__CLASS__, 'preserve_secret_key'], 10, 2);
	}

	public static function register_menu(): void {
		add_options_page(
			__('Media Offloader for CF R2', 'media-offloader-for-cf-r2'),
			__('CF R2', 'media-offloader-for-cf-r2'),
			'manage_options',
			self::MENU_SLUG,
			[__CLASS__, 'render_page']
		);
	}

	/**
	 * Enqueue admin styles for settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_styles(string $hook): void {
		if ($hook !== 'settings_page_' . self::MENU_SLUG) {
			return;
		}

		wp_enqueue_style(
			'r2mo-admin-settings',
			R2MO_URL . 'assets/admin-settings.css',
			[],
			R2MO_VERSION
		);
	}

	public static function register_fields(): void {
		// Register the existing option under this settings group (same option key + same sanitize logic).
		register_setting(
			self::SETTINGS_GROUP,
			Settings::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [Settings::class, 'sanitize'],
				'default'           => [
					'account_id'  => '',
					'access_key'  => '',
					'secret_key'  => '',
					'bucket'      => '',
					'public_url'  => '',
					'path_prefix' => '',
				],
				'show_in_rest'      => false,
			]
		);

		add_settings_section(
			'r2mo_main',
			__('R2 Settings', 'media-offloader-for-cf-r2'),
			static function (): void {
				echo '<p>' . esc_html__('Configure CF R2 credentials and URL settings.', 'media-offloader-for-cf-r2') . '</p>';
			},
			self::SETTINGS_GROUP
		);

		self::add_text_field('account_id', __('Account ID', 'media-offloader-for-cf-r2'), 'text');
		self::add_text_field('access_key', __('Access Key', 'media-offloader-for-cf-r2'), 'text');
		self::add_text_field('secret_key', __('Secret Key', 'media-offloader-for-cf-r2'), 'password');
		self::add_text_field('bucket', __('Bucket Name', 'media-offloader-for-cf-r2'), 'text');
		self::add_text_field('public_url', __('Public URL', 'media-offloader-for-cf-r2'), 'url');
		self::add_text_field('path_prefix', __('Path Prefix', 'media-offloader-for-cf-r2'), 'text');
	}

	/**
	 * Add a settings field bound to the r2mo_settings option.
	 *
	 * @param string $key Field key inside option array.
	 * @param string $label Field label.
	 * @param string $type Input type.
	 */
	private static function add_text_field(string $key, string $label, string $type = 'text'): void {
		add_settings_field(
			'r2mo_' . $key,
			$label,
			static function () use ($key, $type): void {
				$name = Settings::OPTION_KEY . '[' . $key . ']';

				// Never render the secret key back to the browser.
				$value = '';
				if ($key !== 'secret_key') {
					$val = Settings::get($key);
					$value = is_string($val) ? $val : '';
				}

				$attrs = [
					'type'  => $type,
					'name'  => $name,
					'id'    => 'r2mo_' . $key,
					'class' => 'regular-text',
					'value' => $value,
				];

				// Avoid browser auto-fill for secrets.
				if ($key === 'secret_key') {
					$attrs['autocomplete'] = 'new-password';
				}

				echo '<input';
				foreach ($attrs as $attr_key => $attr_val) {
					echo ' ' . esc_attr($attr_key) . '="' . esc_attr((string) $attr_val) . '"';
				}
				echo ' />';

				if ($key === 'secret_key') {
					echo '<p class="description">' . esc_html__('Leave blank to keep the existing secret key.', 'media-offloader-for-cf-r2') . '</p>';
				} elseif ($key === 'path_prefix') {
					echo '<p class="description">' . esc_html__('Optional prefix added before the uploads path in R2 (e.g. "wp"). Use "/" or leave blank for none.', 'media-offloader-for-cf-r2') . '</p>';
				}
			},
			self::SETTINGS_GROUP,
			'r2mo_main'
		);
	}

	public static function render_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$notice_type = isset($_GET[self::NOTICE_QS]) ? sanitize_key((string) wp_unslash($_GET[self::NOTICE_QS])) : '';
		$notice_msg  = isset($_GET[self::NOTICE_MSG_QS]) ? sanitize_text_field((string) wp_unslash($_GET[self::NOTICE_MSG_QS])) : '';
		$sync_notice = isset($_GET[self::SYNC_NOTICE_QS]) ? sanitize_key((string) wp_unslash($_GET[self::SYNC_NOTICE_QS])) : '';
		$sync_processed = isset($_GET[self::SYNC_PROCESSED_QS]) ? (int) $_GET[self::SYNC_PROCESSED_QS] : 0;
		$sync_skipped   = isset($_GET[self::SYNC_SKIPPED_QS]) ? (int) $_GET[self::SYNC_SKIPPED_QS] : 0;
		$sync_failed    = isset($_GET[self::SYNC_FAILED_QS]) ? (int) $_GET[self::SYNC_FAILED_QS] : 0;
		$cleanup_notice = isset($_GET[self::CLEANUP_NOTICE_QS]) ? sanitize_key((string) wp_unslash($_GET[self::CLEANUP_NOTICE_QS])) : '';
		$cleanup_deleted = isset($_GET[self::CLEANUP_DELETED_QS]) ? (int) $_GET[self::CLEANUP_DELETED_QS] : 0;
		$cleanup_skipped = isset($_GET[self::CLEANUP_SKIPPED_QS]) ? (int) $_GET[self::CLEANUP_SKIPPED_QS] : 0;
		$cleanup_failed  = isset($_GET[self::CLEANUP_FAILED_QS]) ? (int) $_GET[self::CLEANUP_FAILED_QS] : 0;
		$restore_notice  = isset($_GET[self::RESTORE_NOTICE_QS]) ? sanitize_key((string) wp_unslash($_GET[self::RESTORE_NOTICE_QS])) : '';
		$restore_restored = isset($_GET[self::RESTORE_RESTORED_QS]) ? (int) $_GET[self::RESTORE_RESTORED_QS] : 0;
		$restore_skipped  = isset($_GET[self::RESTORE_SKIPPED_QS]) ? (int) $_GET[self::RESTORE_SKIPPED_QS] : 0;
		$restore_failed   = isset($_GET[self::RESTORE_FAILED_QS]) ? (int) $_GET[self::RESTORE_FAILED_QS] : 0;
		$purge_notice     = isset($_GET[self::PURGE_NOTICE_QS]) ? sanitize_key((string) wp_unslash($_GET[self::PURGE_NOTICE_QS])) : '';
		$purge_msg        = isset($_GET[self::PURGE_MSG_QS]) ? sanitize_text_field((string) wp_unslash($_GET[self::PURGE_MSG_QS])) : '';
		$webp_notice      = isset($_GET[self::WEBP_NOTICE_QS]) ? sanitize_key((string) wp_unslash($_GET[self::WEBP_NOTICE_QS])) : '';
		$webp_optimized   = isset($_GET[self::WEBP_OPTIMIZED_QS]) ? (int) $_GET[self::WEBP_OPTIMIZED_QS] : 0;
		$webp_skipped     = isset($_GET[self::WEBP_SKIPPED_QS]) ? (int) $_GET[self::WEBP_SKIPPED_QS] : 0;
		$webp_failed      = isset($_GET[self::WEBP_FAILED_QS]) ? (int) $_GET[self::WEBP_FAILED_QS] : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Media Offloader for CF R2', 'media-offloader-for-cf-r2') . '</h1>';

		if ($notice_type === 'success') {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice_msg !== '' ? $notice_msg : __('Connection successful.', 'media-offloader-for-cf-r2')) . '</p></div>';
		} elseif ($notice_type === 'error') {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($notice_msg !== '' ? $notice_msg : __('Connection failed.', 'media-offloader-for-cf-r2')) . '</p></div>';
		}

		if ($sync_notice === 'done') {
			$text = sprintf(
				/* translators: 1: processed count, 2: skipped count, 3: failed count */
				esc_html__('Sync batch complete. Processed: %1$d, Skipped: %2$d, Failed: %3$d.', 'media-offloader-for-cf-r2'),
				$sync_processed,
				$sync_skipped,
				$sync_failed
			);
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($text) . '</p></div>';
		}

		if ($cleanup_notice === 'done') {
			$text = sprintf(
				/* translators: 1: deleted count, 2: skipped count, 3: failed count */
				esc_html__('Cleanup batch complete. Deleted: %1$d, Skipped: %2$d, Failed: %3$d.', 'media-offloader-for-cf-r2'),
				$cleanup_deleted,
				$cleanup_skipped,
				$cleanup_failed
			);
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($text) . '</p></div>';
		}

		if ($restore_notice === 'done') {
			$text = sprintf(
				/* translators: 1: restored count, 2: skipped count, 3: failed count */
				esc_html__('Restore batch complete. Restored: %1$d, Skipped: %2$d, Failed: %3$d.', 'media-offloader-for-cf-r2'),
				$restore_restored,
				$restore_skipped,
				$restore_failed
			);
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($text) . '</p></div>';
		}

		if ($purge_notice === 'done') {
			$text = $purge_msg !== '' ? esc_html($purge_msg) : esc_html__('R2 bucket purge complete.', 'media-offloader-for-cf-r2');
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($text) . '</p></div>';
		} elseif ($purge_notice === 'error') {
			$text = $purge_msg !== '' ? esc_html($purge_msg) : esc_html__('R2 bucket purge failed.', 'media-offloader-for-cf-r2');
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($text) . '</p></div>';
		}

		if ($webp_notice === 'done') {
			$text = sprintf(
				/* translators: 1: optimized count, 2: skipped count, 3: failed count */
				esc_html__('WebP optimization batch complete. Optimized: %1$d, Skipped: %2$d, Failed: %3$d.', 'media-offloader-for-cf-r2'),
				$webp_optimized,
				$webp_skipped,
				$webp_failed
			);
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($text) . '</p></div>';
		}

		// Settings form uses WordPress Settings API which handles nonce verification automatically.
		// settings_fields() includes the nonce field and WordPress verifies it before calling sanitize_callback.
		// Additional explicit nonce field added for plugin check compliance.
		echo '<form method="post" action="options.php" class="r2mo-settings-form">';
		settings_fields(self::SETTINGS_GROUP);
		wp_nonce_field('media_offloader_for_cf_r2_settings', 'media_offloader_for_cf_r2_nonce');
		do_settings_sections(self::SETTINGS_GROUP);
		submit_button(__('Save Changes', 'media-offloader-for-cf-r2'));
		echo '</form>';

		// Action buttons section.
		echo '<div class="r2mo-actions-group r2mo-section-separator">';
		// Test Connection button (separate action, does not save settings).
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="r2mo-action-form">';
		echo '<input type="hidden" name="action" value="r2mo_test_connection" />';
		wp_nonce_field('r2mo_test_connection', '_r2mo_nonce');
		submit_button(__('Test Connection', 'media-offloader-for-cf-r2'), 'secondary', 'submit', false);
		echo '</form>';

		// Sync Existing Media button (processes one batch per request).
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="r2mo-action-form">';
		echo '<input type="hidden" name="action" value="r2mo_sync_existing" />';
		wp_nonce_field('r2mo_sync_existing', '_r2mo_sync_nonce');
		submit_button(__('Sync Existing Media to R2', 'media-offloader-for-cf-r2'), 'secondary', 'submit', false);
		echo '</form>';

		// Safe local media cleanup button (processes one batch per request).
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="r2mo-action-form">';
		echo '<input type="hidden" name="action" value="r2mo_delete_local" />';
		wp_nonce_field('r2mo_delete_local', '_r2mo_delete_local_nonce');
		submit_button(__('Delete Local Media (Safe Cleanup)', 'media-offloader-for-cf-r2'), 'delete', 'submit', false);
		echo '</form>';

		// Restore local media from R2 (processes one batch per request).
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="r2mo-action-form">';
		echo '<input type="hidden" name="action" value="r2mo_restore_local" />';
		wp_nonce_field('r2mo_restore_local', '_r2mo_restore_local_nonce');
		submit_button(__('Restore Local Media from R2', 'media-offloader-for-cf-r2'), 'secondary', 'submit', false);
		echo '</form>';

		// Bulk optimize existing images to WebP (processes one batch per request).
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="r2mo-action-form">';
		echo '<input type="hidden" name="action" value="r2mo_optimize_webp" />';
		wp_nonce_field('r2mo_optimize_webp', '_r2mo_optimize_webp_nonce');
		submit_button(__('Bulk Optimize Existing Images (WebP)', 'media-offloader-for-cf-r2'), 'secondary', 'submit', false);
		echo '</form>';
		echo '</div>';

		// Purge R2 bucket (requires confirmation).
		echo '<div class="r2mo-danger-zone">';
		echo '<h2>' . esc_html__('⚠️ Danger Zone', 'media-offloader-for-cf-r2') . '</h2>';
		echo '<p class="description">' . esc_html__('This will permanently delete ALL objects in your R2 bucket (or under the configured path prefix). This action cannot be undone.', 'media-offloader-for-cf-r2') . '</p>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="r2mo_purge_bucket" />';
		wp_nonce_field('r2mo_purge_bucket', '_r2mo_purge_nonce');
		echo '<p>';
		echo '<label for="r2mo_purge_confirm">' . esc_html__('Type PURGE to confirm:', 'media-offloader-for-cf-r2') . '</label><br />';
		echo '<input type="text" id="r2mo_purge_confirm" name="r2mo_purge_confirm" value="" class="regular-text" autocomplete="off" />';
		echo '</p>';
		submit_button(__('⚠️ Purge CF R2 Bucket', 'media-offloader-for-cf-r2'), 'delete', 'submit', false);
		echo '</form>';
		echo '</div>';

		echo '<div class="r2mo-support-box">';
		echo '<h2>' . esc_html__('Support This Plugin', 'media-offloader-for-cf-r2') . '</h2>';
		echo '<p>' . esc_html__('This plugin is completely free. If it has been helpful, you\'re welcome to support its ongoing maintenance and improvements.', 'media-offloader-for-cf-r2') . '</p>';
		echo '<a class="button button-secondary r2mo-support-button" href="' . esc_url('https://example.com/support') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Support Development', 'media-offloader-for-cf-r2') . '</a>';
		echo '<p class="r2mo-support-footer">' . esc_html__('Created by Majed Talal', 'media-offloader-for-cf-r2') . '</p>';
		echo '</div>';

		echo '</div>';
	}

	public static function handle_test_connection(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'media-offloader-for-cf-r2'));
		}

		check_admin_referer('r2mo_test_connection', '_r2mo_nonce');
		self::redirect_if_sdk_missing();

		$result = R2_Client::instance()->test_connection();

		$args = [
			'page' => self::MENU_SLUG,
		];

		if ($result === true) {
			$args[self::NOTICE_QS]     = 'success';
			$args[self::NOTICE_MSG_QS] = __('Connection successful.', 'media-offloader-for-cf-r2');
		} else {
			$args[self::NOTICE_QS]     = 'error';
			$args[self::NOTICE_MSG_QS] = is_string($result) && $result !== '' ? $result : __('Connection failed.', 'media-offloader-for-cf-r2');
		}

		wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('options-general.php')));
		exit;
	}

	public static function handle_sync_existing(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'media-offloader-for-cf-r2'));
		}

		check_admin_referer('r2mo_sync_existing', '_r2mo_sync_nonce');
		self::redirect_if_sdk_missing();

		$result = \R2MO\r2mo_sync_existing_batch(50);

		$args = [
			'page'                       => self::MENU_SLUG,
			self::SYNC_NOTICE_QS        => 'done',
			self::SYNC_PROCESSED_QS     => $result['processed'],
			self::SYNC_SKIPPED_QS       => $result['skipped'],
			self::SYNC_FAILED_QS        => $result['failed'],
		];

		wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('options-general.php')));
		exit;
	}

	public static function handle_delete_local(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'media-offloader-for-cf-r2'));
		}

		check_admin_referer('r2mo_delete_local', '_r2mo_delete_local_nonce');
		self::redirect_if_sdk_missing();

		$result = \R2MO\r2mo_delete_local_batch(50);

		$args = [
			'page'                     => self::MENU_SLUG,
			self::CLEANUP_NOTICE_QS    => 'done',
			self::CLEANUP_DELETED_QS   => $result['deleted'],
			self::CLEANUP_SKIPPED_QS   => $result['skipped'],
			self::CLEANUP_FAILED_QS    => $result['failed'],
		];

		wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('options-general.php')));
		exit;
	}

	public static function handle_restore_local(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'media-offloader-for-cf-r2'));
		}

		check_admin_referer('r2mo_restore_local', '_r2mo_restore_local_nonce');
		self::redirect_if_sdk_missing();

		$result = \R2MO\r2mo_restore_local_batch(50);

		$args = [
			'page'                      => self::MENU_SLUG,
			self::RESTORE_NOTICE_QS     => 'done',
			self::RESTORE_RESTORED_QS   => $result['restored'],
			self::RESTORE_SKIPPED_QS    => $result['skipped'],
			self::RESTORE_FAILED_QS     => $result['failed'],
		];

		wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('options-general.php')));
		exit;
	}

	public static function handle_purge_bucket(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'media-offloader-for-cf-r2'));
		}

		check_admin_referer('r2mo_purge_bucket', '_r2mo_purge_nonce');
		self::redirect_if_sdk_missing();

		// Require explicit confirmation.
		$confirm = isset($_POST['r2mo_purge_confirm']) ? sanitize_text_field((string) wp_unslash($_POST['r2mo_purge_confirm'])) : '';

		if ($confirm !== 'PURGE') {
			$args = [
				'page'                  => self::MENU_SLUG,
				self::PURGE_NOTICE_QS   => 'error',
				self::PURGE_MSG_QS     => __('Purge aborted. Confirmation text did not match "PURGE".', 'media-offloader-for-cf-r2'),
			];

			wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('options-general.php')));
			exit;
		}

		$result = \R2MO\r2mo_purge_r2_bucket();

		$args = [
			'page' => self::MENU_SLUG,
		];

		if ($result['success']) {
			$args[self::PURGE_NOTICE_QS] = 'done';
			$args[self::PURGE_MSG_QS]     = $result['message'];
		} else {
			$args[self::PURGE_NOTICE_QS] = 'error';
			$args[self::PURGE_MSG_QS]     = $result['message'];
		}

		wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('options-general.php')));
		exit;
	}

	public static function handle_optimize_webp(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'media-offloader-for-cf-r2'));
		}

		check_admin_referer('r2mo_optimize_webp', '_r2mo_optimize_webp_nonce');
		self::redirect_if_sdk_missing();

		$result = \R2MO\r2mo_optimize_webp_batch(20);

		$args = [
			'page'                    => self::MENU_SLUG,
			self::WEBP_NOTICE_QS      => 'done',
			self::WEBP_OPTIMIZED_QS   => $result['optimized'],
			self::WEBP_SKIPPED_QS     => $result['skipped'],
			self::WEBP_FAILED_QS      => $result['failed'],
		];

		wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('options-general.php')));
		exit;
	}

	/**
	 * Preserve secret_key if incoming value is blank.
	 *
	 * @param mixed $new_value New option value.
	 * @param mixed $old_value Old option value.
	 * @return mixed
	 */
	public static function preserve_secret_key($new_value, $old_value) {
		if (! is_array($new_value) || ! is_array($old_value)) {
			return $new_value;
		}

		$new_secret = isset($new_value['secret_key']) ? (string) $new_value['secret_key'] : '';
		$old_secret = isset($old_value['secret_key']) ? (string) $old_value['secret_key'] : '';

		if ($new_secret === '' && $old_secret !== '') {
			$new_value['secret_key'] = $old_secret;
		}

		return $new_value;
	}

	/**
	 * Redirect back to settings page with an error notice if SDK is missing.
	 */
	private static function redirect_if_sdk_missing(): void {
		if (\R2MO\r2mo_is_sdk_available()) {
			return;
		}

		$args = [
			'page'                  => self::MENU_SLUG,
			self::NOTICE_QS         => 'error',
			self::NOTICE_MSG_QS     => \R2MO\r2mo_sdk_missing_message(),
		];

		wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), admin_url('options-general.php')));
		exit;
	}
}

