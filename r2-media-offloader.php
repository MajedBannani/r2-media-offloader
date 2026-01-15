<?php
/**
 * Plugin Name:       Media Offloader for CF R2
 * Description:       Foundation plugin for offloading WordPress media to CF R2 (S3-compatible storage).
 * Version:           1.0.5
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

define('R2MO_VERSION', '1.0.4');
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

add_filter(
	'plugin_row_meta',
	static function (array $links, string $file): array {
		if ($file !== plugin_basename(__FILE__)) {
			return $links;
		}

		$row_links = [
			'github' => [
				'label' => __('GitHub', 'media-offloader-for-cf-r2'),
				'url'   => 'https://github.com/MajedBannani',
			],
			'repository' => [
				'label' => __('View Plugin Repository', 'media-offloader-for-cf-r2'),
				'url'   => 'https://github.com/MajedBannani/r2-media-offloader',
			],
			'release' => [
				'label' => __('Latest Release', 'media-offloader-for-cf-r2'),
				'url'   => 'https://github.com/MajedBannani/r2-media-offloader/releases',
			],
			'website' => [
				'label' => __('Plugin Website', 'media-offloader-for-cf-r2'),
				'url'   => 'https://majedtalal.com',
			],
		];

		foreach ($row_links as $row_link) {
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url($row_link['url']),
				esc_html($row_link['label'])
			);
		}

		return $links;
	},
	10,
	2
);


