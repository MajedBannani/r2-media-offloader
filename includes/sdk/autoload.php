<?php
/**
 * Minimal SDK autoloader for bundled AWS/R2 client.
 *
 * @package R2MO
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

spl_autoload_register(
	static function (string $class): void {
		$prefix   = 'Aws\\';
		$base_dir = __DIR__ . '/Aws/';

		if (strpos($class, $prefix) !== 0) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		if ($relative === '') {
			return;
		}

		$path = $base_dir . str_replace('\\', '/', $relative) . '.php';
		if (file_exists($path)) {
			require_once $path;
		}
	}
);
