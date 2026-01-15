<?php
/**
 * GitHub-based updater for plugin releases.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

final class GitHub_Updater {
	private const API_URL = 'https://api.github.com/repos/MajedBannani/r2-media-offloader/releases/latest';
	private const REPO_URL = 'https://github.com/MajedBannani/r2-media-offloader';
	private const CACHE_KEY = 'r2mo_github_release';
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
	private const ASSET_NAME = 'media-offloader-for-cf-r2.zip';

	/**
	 * Plugin basename (directory/file.php).
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin slug (directory name).
	 *
	 * @var string
	 */
	private string $plugin_slug;

	/**
	 * @param string $plugin_file Plugin basename.
	 */
	private function __construct(string $plugin_file) {
		$this->plugin_file = $plugin_file;
		$this->plugin_slug = dirname($plugin_file);
	}

	/**
	 * Initialize updater hooks.
	 *
	 * @param string $plugin_file Plugin basename.
	 */
	public static function init(string $plugin_file): void {
		$instance = new self($plugin_file);
		add_filter('site_transient_update_plugins', [$instance, 'filter_update_transient']);
		add_filter('plugins_api', [$instance, 'filter_plugins_api'], 10, 3);
		add_filter('upgrader_pre_download', [$instance, 'filter_pre_download'], 10, 3);
	}

	/**
	 * Inject update information into WordPress update transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function filter_update_transient(object $transient): object {
		if (! isset($transient->checked[$this->plugin_file])) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ($release === null) {
			return $transient;
		}

		$current_version = (string) $transient->checked[$this->plugin_file];
		if (! $this->is_newer_version($current_version, $release['version'])) {
			return $transient;
		}

		$update = (object) [
			'slug'        => $this->plugin_slug,
			'plugin'      => $this->plugin_file,
			'new_version' => $release['version'],
			'url'         => self::REPO_URL,
			'package'     => $release['package'],
			'tested'      => '6.9',
			'requires'    => '6.0',
			'requires_php'=> '8.0',
		];

		$transient->response[$this->plugin_file] = $update;
		return $transient;
	}

	/**
	 * Provide plugin information for the update modal.
	 *
	 * @param mixed  $result Result from other plugins.
	 * @param string $action Action.
	 * @param object $args   Args.
	 * @return mixed
	 */
	public function filter_plugins_api($result, string $action, object $args) {
		if ($action !== 'plugin_information') {
			return $result;
		}

		if (! isset($args->slug) || $args->slug !== $this->plugin_slug) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ($release === null) {
			return $result;
		}

		$plugin_data = $this->get_plugin_data();

		return (object) [
			'name'          => $plugin_data['Name'],
			'slug'          => $this->plugin_slug,
			'version'       => $release['version'],
			'author'        => $plugin_data['Author'],
			'homepage'      => self::REPO_URL,
			'download_link' => $release['package'],
			'tested'        => '6.9',
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'sections'      => [
				'description' => $plugin_data['Description'],
			],
		];
	}

	/**
	 * Guard against invalid packages to prevent folder mismatches.
	 *
	 * @param mixed             $reply   Download reply.
	 * @param string            $package Package URL.
	 * @param \WP_Upgrader|null $upgrader Upgrader instance.
	 * @return mixed
	 */
	public function filter_pre_download($reply, string $package, $upgrader) {
		if (! is_object($upgrader) || ! isset($upgrader->skin->plugin)) {
			return $reply;
		}

		if ($upgrader->skin->plugin !== $this->plugin_file) {
			return $reply;
		}

		$release = $this->get_latest_release();
		if ($release === null) {
			return new \WP_Error(
				'r2mo_github_no_release',
				__('Unable to fetch the latest release from GitHub.', 'media-offloader-for-cf-r2')
			);
		}

		if ($package !== $release['package']) {
			// Prevent WordPress from installing a ZIP that extracts to the wrong folder.
			return new \WP_Error(
				'r2mo_github_invalid_package',
				__('Update package is invalid or missing. Please try again later.', 'media-offloader-for-cf-r2')
			);
		}

		return $reply;
	}

	/**
	 * Fetch the latest GitHub release.
	 *
	 * @return array{version:string,package:string,published_at:string}|null
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient(self::CACHE_KEY);
		if (is_array($cached)) {
			return $cached;
		}

		$response = wp_remote_get(
			self::API_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
				],
			]
		);

		if (is_wp_error($response)) {
			return null;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		if ($code !== 200 || ! is_string($body) || $body === '') {
			return null;
		}

		$data = json_decode($body, true);
		if (! is_array($data)) {
			return null;
		}

		$tag_name  = isset($data['tag_name']) ? (string) $data['tag_name'] : '';
		$published = isset($data['published_at']) ? (string) $data['published_at'] : '';

		if ($tag_name === '') {
			return null;
		}

		$assets  = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : [];
		$package = $this->find_release_asset_url($assets);
		if ($package === '') {
			return null;
		}

		$version = ltrim($tag_name, 'v');

		$release = [
			'version'      => $version,
			'package'      => $package,
			'published_at' => $published,
		];

		set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

		return $release;
	}

	/**
	 * Find a valid release asset URL.
	 *
	 * @param array<int, mixed> $assets Assets array.
	 * @return string
	 */
	private function find_release_asset_url(array $assets): string {
		foreach ($assets as $asset) {
			if (! is_array($asset)) {
				continue;
			}

			$name = isset($asset['name']) ? (string) $asset['name'] : '';
			$url  = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

			if ($name === '' || $url === '') {
				continue;
			}

			if ($name === self::ASSET_NAME) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Compare versions.
	 */
	private function is_newer_version(string $current, string $latest): bool {
		if ($current === '' || $latest === '') {
			return false;
		}

		return version_compare($latest, $current, '>');
	}

	/**
	 * Fetch plugin data for the update modal.
	 *
	 * @return array<string, string>
	 */
	private function get_plugin_data(): array {
		if (! function_exists('get_plugin_data')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data(R2MO_PATH . 'r2-media-offloader.php', false, false);

		return [
			'Name'        => isset($data['Name']) ? (string) $data['Name'] : 'Media Offloader for CF R2',
			'Description' => isset($data['Description']) ? (string) $data['Description'] : '',
			'Author'      => isset($data['Author']) ? (string) $data['Author'] : '',
		];
	}
}
