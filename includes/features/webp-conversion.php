<?php
/**
 * Automatic WebP conversion for image uploads.
 *
 * Converts jpg/jpeg/png images to WebP format before R2 upload.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Check if a file should be converted to WebP.
 *
 * @param string $file_path File path.
 * @param string $mime_type MIME type.
 * @return bool
 */
function r2mo_should_convert_to_webp(string $file_path, string $mime_type): bool {
	// Only convert image files.
	if (! str_starts_with($mime_type, 'image/')) {
		return false;
	}

	// Skip already WebP files.
	if ($mime_type === 'image/webp') {
		return false;
	}

	// Skip unsupported formats.
	$supported = ['image/jpeg', 'image/jpg', 'image/png'];
	if (! in_array(strtolower($mime_type), $supported, true)) {
		return false;
	}

	// Check file extension as additional safeguard.
	$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
	$supported_exts = ['jpg', 'jpeg', 'png'];
	if (! in_array($ext, $supported_exts, true)) {
		return false;
	}

	return true;
}

/**
 * Convert an image file to WebP format.
 *
 * @param string $file_path Original file path.
 * @param string $mime_type Original MIME type.
 * @return array{success:bool,webp_path:string,error:string}
 */
function r2mo_convert_image_to_webp(string $file_path, string $mime_type): array {
	try {
		if (! file_exists($file_path) || ! is_readable($file_path)) {
			return [
				'success'   => false,
				'webp_path' => '',
				'error'     => 'File does not exist or is not readable.',
			];
		}

		// Check if GD or Imagick supports WebP.
		$editor = wp_get_image_editor($file_path);
		if (is_wp_error($editor)) {
			return [
				'success'   => false,
				'webp_path' => '',
				'error'     => $editor->get_error_message(),
			];
		}

		// Check if editor supports WebP.
		$supports = $editor->supports_mime_type('image/webp');
		if (! $supports) {
			return [
				'success'   => false,
				'webp_path' => '',
				'error'     => 'Image editor does not support WebP conversion.',
			];
		}

		// Generate WebP path.
		$webp_path = preg_replace('/\.(jpg|jpeg|png)$/i', '.webp', $file_path);
		if (! is_string($webp_path) || $webp_path === $file_path) {
			return [
				'success'   => false,
				'webp_path' => '',
				'error'     => 'Failed to generate WebP file path.',
			];
		}

		// Load and resize (maintain original dimensions).
		$loaded = $editor->load();
		if (is_wp_error($loaded)) {
			return [
				'success'   => false,
				'webp_path' => '',
				'error'     => $loaded->get_error_message(),
			];
		}

		// Get original dimensions.
		$size = $editor->get_size();
		if (! isset($size['width']) || ! isset($size['height'])) {
			return [
				'success'   => false,
				'webp_path' => '',
				'error'     => 'Failed to get image dimensions.',
			];
		}

		// Resize to maintain dimensions (handles orientation).
		$resized = $editor->resize($size['width'], $size['height'], false);
		if (is_wp_error($resized)) {
			return [
				'success'   => false,
				'webp_path' => '',
				'error'     => $resized->get_error_message(),
			];
		}

		// Save as WebP with quality 80.
		$saved = $editor->save($webp_path, 'image/webp');
		if (is_wp_error($saved)) {
			return [
				'success'   => false,
				'webp_path' => '',
				'error'     => $saved->get_error_message(),
			];
		}

		// Verify WebP file was created.
		if (! isset($saved['path']) || ! file_exists($saved['path'])) {
			return [
				'success'   => false,
				'webp_path' => '',
				'error'     => 'WebP file was not created.',
			];
		}

		$final_webp_path = (string) $saved['path'];

		// Delete original file.
		if (file_exists($file_path)) {
			wp_delete_file($file_path);
		}

		return [
			'success'   => true,
			'webp_path' => $final_webp_path,
			'error'     => '',
		];
	} catch (\Throwable $e) {
		return [
			'success'   => false,
			'webp_path' => '',
			'error'     => $e->getMessage(),
		];
	}
}

/**
 * Convert uploaded image to WebP if applicable.
 *
 * Hook: wp_handle_upload (priority 15, runs before R2 upload at priority 20).
 *
 * @param array $upload Upload array with 'file', 'url', 'type'.
 * @return array Modified upload array.
 */
add_filter(
	'wp_handle_upload',
	static function (array $upload): array {
		try {
			// Validate upload array.
			if (! isset($upload['file']) || ! isset($upload['type'])) {
				return $upload;
			}

			$file_path = (string) $upload['file'];
			$mime_type = (string) $upload['type'];

			// Check if conversion is needed.
			if (! r2mo_should_convert_to_webp($file_path, $mime_type)) {
				return $upload;
			}

			// Convert to WebP.
			$result = r2mo_convert_image_to_webp($file_path, $mime_type);

			if (! $result['success']) {
				// Continue with original file on failure.
				return $upload;
			}

			// Update upload array to point to WebP file.
			$webp_path = $result['webp_path'];
			if ($webp_path !== '' && file_exists($webp_path)) {
				$upload['file'] = $webp_path;

				// Update MIME type.
				$upload['type'] = 'image/webp';

				// Update URL if present.
				if (isset($upload['url'])) {
					$uploads = wp_get_upload_dir();
					$baseurl = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
					if ($baseurl !== '') {
						$relative = r2mo_get_uploads_relative_path($webp_path);
						if ($relative !== '') {
							$upload['url'] = trailingslashit($baseurl) . $relative;
						}
					}
				}
			}
		} catch (\Throwable $e) {
			// Never break the upload pipeline.
		}

		return $upload;
	},
	15,
	1
);
