<?php
/**
 * Main plugin loader.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

final class Plugin {
	/**
	 * Initialize plugin.
	 */
	public static function init(): void {
		self::load_dependencies();
		self::init_services();
		self::register_placeholders();
	}

	/**
	 * Load required files.
	 */
	private static function load_dependencies(): void {
		require_once R2MO_PATH . 'includes/helpers.php';
		require_once R2MO_PATH . 'includes/class-settings.php';
		require_once R2MO_PATH . 'includes/class-r2-client.php';
		require_once R2MO_PATH . 'includes/services/class-url-rewriter.php';
		require_once R2MO_PATH . 'includes/features/webp-conversion.php';
		require_once R2MO_PATH . 'includes/features/upload-new-media.php';
		require_once R2MO_PATH . 'includes/features/rewrite-urls.php';
		require_once R2MO_PATH . 'includes/features/sync-existing-media.php';
		require_once R2MO_PATH . 'includes/features/delete-local-media.php';
		require_once R2MO_PATH . 'includes/features/restore-local-media.php';
		require_once R2MO_PATH . 'includes/features/purge-r2-bucket.php';
		require_once R2MO_PATH . 'includes/features/bulk-webp-optimization.php';
		require_once R2MO_PATH . 'cli/class-sync-cli.php';
	}

	/**
	 * Initialize core services.
	 */
	private static function init_services(): void {
		Settings::init();
	}

	/**
	 * Prepare future hooks (Admin UI, WP-CLI, etc) without implementing features yet.
	 */
	private static function register_placeholders(): void {
		// Admin-only initialization (settings UI, etc).
		if (is_admin()) {
			require_once R2MO_PATH . 'includes/admin/settings-page.php';
			\R2MO\Admin\Settings_Page::init();
		}

		// WP-CLI commands are registered from their own files when WP_CLI is available.
	}
}


