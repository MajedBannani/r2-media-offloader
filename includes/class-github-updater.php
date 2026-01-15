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
	private const ASSET_NAME = 'r2-media-offloader.zip';
	private const EXPECTED_SLUG = 'r2-media-offloader';
	private const MAIN_FILE = 'r2-media-offloader.php';
	private const NOTICE_KEY = 'r2mo_github_release_notice';

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
		add_filter('upgrader_source_selection', [$instance, 'filter_source_selection'], 10, 4);
		add_filter('upgrader_post_install', [$instance, 'filter_post_install'], 10, 3);
		add_action('admin_notices', [$instance, 'render_admin_notice']);
	}

	/**
	 * Inject update information into WordPress update transient.
	 *
	 * @param object|false $transient Update transient.
	 * @return object|false
	 */
	public function filter_update_transient(object|false $transient): object|false {
		if (! is_object($transient)) {
			return $transient;
		}

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
	 * Abort updates if the extracted folder name is not stable.
	 *
	 * WordPress requires the extracted folder to match the installed plugin folder
	 * exactly; otherwise it treats the update as a different plugin and deactivates it.
	 *
	 * @param string|\WP_Error $source        Source directory.
	 * @param string           $remote_source Remote source directory.
	 * @param \WP_Upgrader     $upgrader      Upgrader instance.
	 * @param array<string,mixed> $hook_extra Hook extra arguments.
	 * @return string|\WP_Error
	 */
	public function filter_source_selection($source, string $remote_source, \WP_Upgrader $upgrader, array $hook_extra) {
		if (is_wp_error($source)) {
			return $source;
		}

		if (! isset($upgrader->skin->plugin) || $upgrader->skin->plugin !== $this->plugin_file) {
			return $source;
		}

		$folder = basename(wp_normalize_path(untrailingslashit($source)));
		if ($folder !== self::EXPECTED_SLUG) {
			return new \WP_Error(
				'r2mo_github_folder_mismatch',
				__('Update package folder name is invalid. Update aborted to prevent plugin deactivation.', 'media-offloader-for-cf-r2')
			);
		}

		$main_file = trailingslashit($source) . self::MAIN_FILE;
		if (! file_exists($main_file)) {
			return new \WP_Error(
				'r2mo_github_missing_main_file',
				__('Update package is missing the main plugin file. Update aborted.', 'media-offloader-for-cf-r2')
			);
		}

		return $source;
	}

	/**
	 * Validate installed plugin after update and abort on mismatch.
	 *
	 * @param mixed               $response  Install response.
	 * @param array<string,mixed> $hook_extra Hook extra arguments.
	 * @param array<string,mixed> $result    Install result.
	 * @return mixed
	 */
	public function filter_post_install($response, array $hook_extra, array $result) {
		if (is_wp_error($response)) {
			return $response;
		}

		if (! isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
			return $response;
		}

		$destination = isset($result['destination']) ? (string) $result['destination'] : '';
		if ($destination === '') {
			return new \WP_Error(
				'r2mo_github_invalid_destination',
				__('Update destination is invalid. Update aborted.', 'media-offloader-for-cf-r2')
			);
		}

		$folder = basename(wp_normalize_path(untrailingslashit($destination)));
		if ($folder !== self::EXPECTED_SLUG) {
			return new \WP_Error(
				'r2mo_github_post_folder_mismatch',
				__('Update installed into an invalid folder. Update aborted to prevent plugin deactivation.', 'media-offloader-for-cf-r2')
			);
		}

		$main_file = trailingslashit($destination) . self::MAIN_FILE;
		if (! file_exists($main_file)) {
			return new \WP_Error(
				'r2mo_github_post_missing_main_file',
				__('Update is missing the main plugin file. Update aborted.', 'media-offloader-for-cf-r2')
			);
		}

		return $response;
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
			$this->set_notice(
				__('Official release ZIP asset not found on GitHub. Updates are temporarily unavailable.', 'media-offloader-for-cf-r2')
			);
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
	 * Store a short-lived admin notice message.
	 *
	 * @param string $message Notice message.
	 */
	private function set_notice(string $message): void {
		set_transient(self::NOTICE_KEY, $message, 6 * HOUR_IN_SECONDS);
	}

	/**
	 * Render admin notice for updater issues.
	 */
	public function render_admin_notice(): void {
		if (! current_user_can('update_plugins')) {
			return;
		}

		$message = get_transient(self::NOTICE_KEY);
		if (! is_string($message) || $message === '') {
			return;
		}

		delete_transient(self::NOTICE_KEY);

		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($message) . '</p></div>';
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
