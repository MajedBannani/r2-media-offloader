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


