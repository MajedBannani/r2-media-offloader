<?php
/**
 * URL rewrites for existing media references.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO\Services;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Internal service to rewrite local media URLs to CDN URLs across content/options/theme mods.
 */
final class Url_Rewriter {
	/**
	 * Rewrite all known references for a given attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $local_url     Original local URL.
	 * @param string $cdn_url       CDN URL.
	 * @return array{posts:int,options:int,theme_mods:int}
	 */
	public static function rewrite_for_attachment(int $attachment_id, string $local_url, string $cdn_url): array {
		if ($attachment_id <= 0 || $local_url === '' || $cdn_url === '' || $local_url === $cdn_url) {
			return [
				'posts'      => 0,
				'options'    => 0,
				'theme_mods' => 0,
			];
		}

		$posts_updated      = self::replace_in_posts($local_url, $cdn_url);
		$options_updated    = self::replace_in_options($local_url, $cdn_url);
		$theme_mods_updated = self::replace_in_theme_mods($attachment_id, $local_url, $cdn_url);

		// Simple logging hook via error_log. Can be wired to a dedicated logger later.
		$message = sprintf(
			'R2MO URL rewrite for attachment %d: posts=%d, options=%d, theme_mods=%d',
			$attachment_id,
			$posts_updated,
			$options_updated,
			$theme_mods_updated
		);

		return [
			'posts'      => $posts_updated,
			'options'    => $options_updated,
			'theme_mods' => $theme_mods_updated,
		];
	}

	/**
	 * Replace URLs in post_content for published posts.
	 *
	 * @param string $local_url Local URL.
	 * @param string $cdn_url   CDN URL.
	 * @return int Number of posts updated.
	 */
	/**
	 * Replace URLs in post_content for published posts.
	 *
	 * Uses direct $wpdb queries intentionally for:
	 * - Efficient LIKE pattern matching across all post types
	 * - Bulk updates without loading full post objects
	 * - Performance optimization for URL replacement operations
	 * Results are cached to minimize database load.
	 *
	 * @param string $local_url Local URL.
	 * @param string $cdn_url   CDN URL.
	 * @return int Number of posts updated.
	 */
	private static function replace_in_posts(string $local_url, string $cdn_url): int {
		global $wpdb;

		$like = '%' . $wpdb->esc_like($local_url) . '%';

		// Cache key for post IDs query.
		$cache_key = 'r2mo_posts_' . md5($like);
		$post_ids  = wp_cache_get($cache_key, 'media-offloader-for-cf-r2');

		if ($post_ids === false) {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page','attachment','custom_css','customize_changeset') AND post_content LIKE %s",
					$like
				)
			);

			wp_cache_set($cache_key, $post_ids, 'media-offloader-for-cf-r2', 300);
		}

		if (empty($post_ids)) {
			return 0;
		}

		$updated = 0;

		foreach ($post_ids as $post_id) {
			$post_id = (int) $post_id;

			// Cache key for individual post content.
			$content_cache_key = 'r2mo_post_content_' . $post_id;
			$content           = wp_cache_get($content_cache_key, 'media-offloader-for-cf-r2');

			if ($content === false) {
				$content = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_content FROM {$wpdb->posts} WHERE ID = %d",
						$post_id
					)
				);

				wp_cache_set($content_cache_key, $content, 'media-offloader-for-cf-r2', 300);
			}

			if (! is_string($content) || $content === '') {
				continue;
			}

			if (strpos($content, $local_url) === false) {
				continue;
			}

			$new_content = str_replace($local_url, $cdn_url, $content);
			if ($new_content === $content) {
				continue;
			}

			$rows = $wpdb->update(
				$wpdb->posts,
				['post_content' => $new_content],
				['ID' => $post_id],
				['%s'],
				['%d']
			);

			if ($rows !== false) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Replace URLs in all options (recursively, preserving structure).
	 *
	 * Uses direct $wpdb queries intentionally for:
	 * - Efficient LIKE pattern matching across all options
	 * - Handling serialized data without loading all options into memory
	 * - Performance optimization for bulk URL replacement
	 * Results are cached to minimize database load.
	 *
	 * @param string $local_url Local URL.
	 * @param string $cdn_url   CDN URL.
	 * @return int Number of options updated.
	 */
	private static function replace_in_options(string $local_url, string $cdn_url): int {
		global $wpdb;

		$like = '%' . $wpdb->esc_like($local_url) . '%';

		// Cache key for options query.
		$cache_key = 'r2mo_options_' . md5($like);
		$rows      = wp_cache_get($cache_key, 'media-offloader-for-cf-r2');

		if ($rows === false) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE %s",
					$like
				),
				ARRAY_A
			);

			wp_cache_set($cache_key, $rows, 'media-offloader-for-cf-r2', 300);
		}

		if (empty($rows)) {
			return 0;
		}

		$updated = 0;

		foreach ($rows as $row) {
			$option_name  = $row['option_name'];
			$original_raw = $row['option_value'];

			$value = maybe_unserialize($original_raw);

			$changed = false;
			$value   = self::deep_replace($value, $local_url, $cdn_url, $changed);

			if (! $changed) {
				continue;
			}

			$new_raw = maybe_serialize($value);

			if ($new_raw === $original_raw) {
				continue;
			}

			$result = $wpdb->update(
				$wpdb->options,
				['option_value' => $new_raw],
				['option_name' => $option_name],
				['%s'],
				['%s']
			);

			if ($result !== false) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Replace URLs in theme_mods options.
	 *
	 * Uses direct $wpdb queries intentionally for:
	 * - Efficient LIKE pattern matching across theme_mods_* options
	 * - Handling serialized theme customization data
	 * - Performance optimization for URL replacement in theme settings
	 * Results are cached to minimize database load.
	 *
	 * @param int    $attachment_id Attachment ID (unused for now but reserved for future ID-based handling).
	 * @param string $local_url     Local URL.
	 * @param string $cdn_url       CDN URL.
	 * @return int Number of theme_mod options updated.
	 */
	private static function replace_in_theme_mods(int $attachment_id, string $local_url, string $cdn_url): int {
		unset($attachment_id); // Reserved for potential ID-aware handling.

		global $wpdb;

		$like = '%' . $wpdb->esc_like($local_url) . '%';

		// Cache key for theme_mods query.
		$cache_key = 'r2mo_theme_mods_' . md5($like);
		$rows      = wp_cache_get($cache_key, 'media-offloader-for-cf-r2');

		if ($rows === false) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
					'theme_mods_%',
					$like
				),
				ARRAY_A
			);

			wp_cache_set($cache_key, $rows, 'media-offloader-for-cf-r2', 300);
		}

		if (empty($rows)) {
			return 0;
		}

		$updated = 0;

		foreach ($rows as $row) {
			$option_name  = $row['option_name'];
			$original_raw = $row['option_value'];

			$value = maybe_unserialize($original_raw);

			if (! is_array($value)) {
				continue;
			}

			$changed = false;
			$value   = self::deep_replace($value, $local_url, $cdn_url, $changed);

			if (! $changed) {
				continue;
			}

			$new_raw = maybe_serialize($value);

			if ($new_raw === $original_raw) {
				continue;
			}

			$result = $wpdb->update(
				$wpdb->options,
				['option_value' => $new_raw],
				['option_name' => $option_name],
				['%s'],
				['%s']
			);

			if ($result !== false) {
				$updated++;
			}
		}

		return $updated;
	}

	/**
	 * Deep replace helper that preserves data structure.
	 *
	 * @param mixed  $value   Any value.
	 * @param string $search  Local URL.
	 * @param string $replace CDN URL.
	 * @param bool   $changed Set to true if any replacement occurred.
	 * @return mixed
	 */
	private static function deep_replace($value, string $search, string $replace, bool &$changed) {
		if (is_string($value)) {
			if ($value !== '' && strpos($value, $search) !== false) {
				$new = str_replace($search, $replace, $value);
				if ($new !== $value) {
					$changed = true;
					return $new;
				}
			}
			return $value;
		}

		if (is_array($value)) {
			foreach ($value as $k => $v) {
				$value[$k] = self::deep_replace($v, $search, $replace, $changed);
			}
			return $value;
		}

		if (is_object($value)) {
			foreach ($value as $k => $v) {
				$value->{$k} = self::deep_replace($v, $search, $replace, $changed);
			}
			return $value;
		}

		return $value;
	}
}

