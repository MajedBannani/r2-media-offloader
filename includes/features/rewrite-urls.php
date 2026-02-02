<?php
/**
 * Rewrite attachment URLs to public CDN/R2 URLs for offloaded attachments.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Build a public URL for an R2 object key.
 *
 * @param string $key R2 object key (may include path_prefix).
 * @return string Public URL or empty string if not possible.
 */
function r2mo_public_url_for_key(string $key): string {
	$base = trim((string) Settings::get('public_url'));
	if ($base === '') {
		return '';
	}

	$base = untrailingslashit($base);
	$key  = ltrim($key, '/');
	if ($key === '') {
		return '';
	}

	return $base . '/' . $key;
}

add_filter(
	'wp_get_attachment_url',
	static function ($url, int $attachment_id) {
		if (! is_string($url) || $url === '' || $attachment_id <= 0) {
			return $url;
		}

		$key = get_post_meta($attachment_id, '_r2_key', true);
		if (! is_string($key) || $key === '') {
			$offloaded = get_post_meta($attachment_id, '_r2_offloaded', true);
			if (! $offloaded) {
				return $url;
			}

			return $url;
		}

		$public = r2mo_public_url_for_key($key);
		if ($public === '') {
			return $url;
		}

		return $public;
	},
	20,
	2
);

