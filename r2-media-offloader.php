<?php
/**
 * Plugin Name:       Media Offloader for CF R2
 * Description:       Foundation plugin for offloading WordPress media to CF R2 (S3-compatible storage).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Majed Talal
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       media-offloader-for-cf-r2
 *
 * @package R2MO
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('R2MO_VERSION', '1.0.0');
define('R2MO_PATH', plugin_dir_path(__FILE__));
define('R2MO_URL', plugin_dir_url(__FILE__));

// Load SDK autoloader if bundled. Avoid hard dependency on vendor/ for WP.org.
$autoload_candidates = [
	R2MO_PATH . 'vendor/autoload.php',
	R2MO_PATH . 'includes/sdk/autoload.php',
];

foreach ($autoload_candidates as $autoload_path) {
	if (file_exists($autoload_path)) {
		require_once $autoload_path;
		break;
	}
}

require_once R2MO_PATH . 'includes/class-plugin.php';

add_action(
	'plugins_loaded',
	static function (): void {
		\R2MO\Plugin::init();
	}
);


