<?php
/**
 * WP-CLI commands for syncing existing media to CF R2.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO\CLI;

use function R2MO\r2mo_offload_attachment_to_r2;
use function R2MO\r2mo_delete_local_for_attachment;
use function R2MO\r2mo_restore_local_for_attachment;
use function R2MO\r2mo_purge_r2_bucket;
use function R2MO\r2mo_optimize_attachment_to_webp;
use WP_CLI;

if (! defined('ABSPATH')) {
	exit;
}

if (defined('WP_CLI') && WP_CLI) {
	/**
		 * Sync existing media to CF R2 and manage safe local cleanup/restore.
	 */
	final class Sync_CLI {
		/**
		 * Whether shutdown handler has been registered.
		 *
		 * @var bool
		 */
		private static bool $shutdown_registered = false;

		/**
		 * Register the command with WP-CLI.
		 */
		public static function register(): void {
			if (! self::$shutdown_registered) {
				register_shutdown_function([__CLASS__, 'shutdown_handler']);
				self::$shutdown_registered = true;
			}

			WP_CLI::add_command('r2 sync-existing', [__CLASS__, 'cmd_sync_existing']);
			WP_CLI::add_command('r2 delete-local', [__CLASS__, 'cmd_delete_local']);
			WP_CLI::add_command('r2 restore-local', [__CLASS__, 'cmd_restore_local']);
			WP_CLI::add_command('r2 purge', [__CLASS__, 'cmd_purge']);
			WP_CLI::add_command('r2 optimize-webp', [__CLASS__, 'cmd_optimize_webp']);
		}

		/**
		 * Shutdown handler to catch and report fatal errors in CLI context.
		 */
		public static function shutdown_handler(): void {
			if (php_sapi_name() !== 'cli') {
				return;
			}

			$error = error_get_last();
			if (! $error) {
				return;
			}

			$type = isset($error['type']) ? (int) $error['type'] : 0;

			// Only react to fatal-like errors.
			if (in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
				$message = isset($error['message']) ? (string) $error['message'] : '';
				WP_CLI::warning('CF R2 command terminated safely after a fatal error: ' . $message);
			}
		}

		/**
		 * Sync existing media attachments to CF R2.
		 *
		 * Uploads all media files that haven't been offloaded yet to your configured
		 * CF R2 bucket. This command processes attachments in batches and
		 * updates attachment metadata to track offloaded status.
		 *
		 * ## OPTIONS
		 *
		 * [--batch=<number>]
		 * : Number of attachments to process per query batch (default: 200).
		 *
		 * ## EXAMPLES
		 *
		 *     # Sync all existing media to R2
		 *     wp r2 sync-existing
		 *
		 *     # Sync with custom batch size
		 *     wp r2 sync-existing --batch=500
		 *
		 * ## SAFETY
		 *
		 * - Never modifies or deletes local files
		 * - Skips already offloaded attachments
		 * - Continues processing on individual failures
		 * - Always terminates cleanly
		 *
		 * @since 1.0.0
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public static function cmd_sync_existing(array $args, array $assoc_args): void {
			try {
				$limit = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 200;

				WP_CLI::log('Starting CF R2 existing media sync...');

				// Get total count first for logging and safety guard.
				$count_query = new \WP_Query(
					[
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						// This meta_query is used intentionally for WP-CLI and batch processing.
						// It does not run on frontend requests, and performance impact is acceptable.
						'meta_query'     => [
							'relation' => 'OR',
							[
								'key'     => '_r2_offloaded',
								'compare' => 'NOT EXISTS',
							],
							[
								'key'     => '_r2_offloaded',
								'value'   => true,
								'compare' => '!=',
							],
						],
					]
				);

				$total_found = ! empty($count_query->posts) ? count($count_query->posts) : 0;
				unset($count_query);

				WP_CLI::log(sprintf('Found %d attachments to process.', $total_found));

				if ($total_found === 0) {
					WP_CLI::success('No attachments found to process.');
					return;
				}

				$total_processed = 0;
				$total_skipped   = 0;
				$total_failed    = 0;
				$total_handled   = 0;
				$iteration       = 0;
				$page            = 1;

				while ($total_handled < $total_found) {
					$query = new \WP_Query(
						[
							'post_type'      => 'attachment',
							'post_status'    => 'inherit',
							'posts_per_page' => $limit,
							'paged'          => $page,
							'fields'         => 'ids',
							'orderby'        => 'ID',
							'order'          => 'ASC',
							// meta_query is used intentionally for CLI and batch processing.
							// This code does not run on frontend requests.
							'meta_query'     => [
								'relation' => 'OR',
								[
									'key'     => '_r2_offloaded',
									'compare' => 'NOT EXISTS',
								],
								[
									'key'     => '_r2_offloaded',
									'value'   => true,
									'compare' => '!=',
								],
							],
						]
					);

					if (empty($query->posts)) {
						unset($query);
						break;
					}

					foreach ($query->posts as $attachment_id) {
						$attachment_id = (int) $attachment_id;
						$iteration++;
						$total_handled++;

						try {
							$result = r2mo_offload_attachment_to_r2($attachment_id);
						} catch (\Throwable $e) {
							$total_failed++;
							WP_CLI::warning(
								sprintf(
									'#%d - error during sync (msg=%s)',
									$attachment_id,
									$e->getMessage()
								)
							);
							unset($result);
							continue;
						}

						$message = sprintf(
							'#%d - %s (%s)',
							$attachment_id,
							$result['status'],
							$result['message']
						);

						if ($result['status'] === 'processed') {
							$total_processed++;
							WP_CLI::log($message);
						} elseif ($result['status'] === 'failed') {
							$total_failed++;
							WP_CLI::warning($message);
						} else {
							$total_skipped++;
							WP_CLI::log($message);
						}

						unset($result);

						if ($iteration % 10 === 0) {
							gc_collect_cycles();
						}
					}

					unset($query);

					$page++;

					// Safety guard: prevent infinite loops.
					if ($total_handled >= $total_found) {
						break;
					}

					// Allow other processes a chance to run.
					sleep(1);
				}

				WP_CLI::log(sprintf('Processed %d attachments. Command finished.', $total_handled));
				WP_CLI::success(
					sprintf(
						'Sync complete. Processed: %d, Skipped: %d, Failed: %d',
						$total_processed,
						$total_skipped,
						$total_failed
					)
				);
			} catch (\Throwable $e) {
				WP_CLI::warning('CF R2 sync-existing command aborted safely: ' . $e->getMessage());
			}
		}

		/**
		 * Delete local media files that have been safely offloaded to R2.
		 *
		 * Removes local files from disk for attachments that are confirmed to exist
		 * in CF R2. This helps recover disk space while maintaining full
		 * functionality through CDN delivery.
		 *
		 * ## OPTIONS
		 *
		 * [--batch=<number>]
		 * : Number of attachments to process per query batch (default: 200).
		 *
		 * ## EXAMPLES
		 *
		 *     # Delete local files for all offloaded media
		 *     wp r2 delete-local
		 *
		 *     # Process with custom batch size
		 *     wp r2 delete-local --batch=500
		 *
		 * ## SAFETY
		 *
		 * - Only deletes files for attachments with _r2_offloaded=true
		 * - Verifies object exists in R2 via HEAD request before deletion
		 * - Never deletes attachment posts or metadata
		 * - Skips files that don't exist or aren't writable
		 * - Continues processing on individual failures
		 *
		 * @since 1.0.0
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public static function cmd_delete_local(array $args, array $assoc_args): void {
			try {
				$limit = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 200;

				WP_CLI::log('Starting CF R2 safe local media cleanup...');

				// Get total count first for logging and safety guard.
				$count_query = new \WP_Query(
					[
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						// This meta_query is used intentionally for WP-CLI and batch processing.
						// It does not run on frontend requests, and performance impact is acceptable.
						'meta_query'     => [
							'relation' => 'AND',
							[
								'key'   => '_r2_offloaded',
								'value' => true,
							],
							[
								'key'     => '_r2_local_deleted',
								'compare' => 'NOT EXISTS',
							],
						],
					]
				);

				$total_found = ! empty($count_query->posts) ? count($count_query->posts) : 0;
				unset($count_query);

				WP_CLI::log(sprintf('Found %d attachments to process.', $total_found));

				if ($total_found === 0) {
					WP_CLI::success('No attachments found to process.');
					return;
				}

				$total_processed = 0;
				$total_deleted   = 0;
				$total_skipped   = 0;
				$total_failed    = 0;
				$iteration       = 0;
				$page            = 1;

				while ($total_processed < $total_found) {
					$query = new \WP_Query(
						[
							'post_type'      => 'attachment',
							'post_status'    => 'inherit',
							'posts_per_page' => $limit,
							'paged'          => $page,
							'fields'         => 'ids',
							'orderby'        => 'ID',
							'order'          => 'ASC',
							// meta_query is used intentionally for CLI and batch processing.
							// This code does not run on frontend requests.
							'meta_query'     => [
								'relation' => 'AND',
								[
									'key'   => '_r2_offloaded',
									'value' => true,
								],
								[
									'key'     => '_r2_local_deleted',
									'compare' => 'NOT EXISTS',
								],
							],
						]
					);

					if (empty($query->posts)) {
						unset($query);
						break;
					}

					foreach ($query->posts as $attachment_id) {
						$attachment_id = (int) $attachment_id;
						$total_processed++;
						$iteration++;

						try {
							$result = r2mo_delete_local_for_attachment($attachment_id);
						} catch (\Throwable $e) {
							$total_failed++;
							WP_CLI::warning(
								sprintf(
									'#%d - error (msg=%s)',
									$attachment_id,
									$e->getMessage()
								)
							);
							unset($result);
							continue;
						}

						$total_deleted += $result['deleted'];
						$total_skipped += $result['skipped'];
						$total_failed  += $result['failed'];

						$message = sprintf(
							'#%d - %s (deleted=%d, skipped=%d, failed=%d, msg=%s)',
							$attachment_id,
							$result['status'],
							$result['deleted'],
							$result['skipped'],
							$result['failed'],
							$result['message']
						);

						if ($result['status'] === 'deleted' || $result['status'] === 'partial') {
							WP_CLI::log($message);
						} elseif ($result['status'] === 'failed') {
							WP_CLI::warning($message);
						} else {
							WP_CLI::log($message);
						}

						unset($result);

						if ($iteration % 10 === 0) {
							gc_collect_cycles();
						}
					}

					unset($query);

					$page++;

					// Safety guard: prevent infinite loops.
					if ($total_processed >= $total_found) {
						break;
					}

					sleep(1);
				}

				WP_CLI::log(sprintf('Processed %d attachments. Command finished.', $total_processed));
				WP_CLI::success(
					sprintf(
						'Local cleanup complete. Attachments processed: %d, Files deleted: %d, Skipped: %d, Failed: %d',
						$total_processed,
						$total_deleted,
						$total_skipped,
						$total_failed
					)
				);
			} catch (\Throwable $e) {
				WP_CLI::warning('CF R2 delete-local command aborted safely: ' . $e->getMessage());
			}
		}

		/**
		 * Restore local media files from CF R2 where they are missing.
		 *
		 * Downloads files from CF R2 back to local storage for attachments
		 * that have been offloaded. This is useful for migration, backup, or when
		 * you need local files again.
		 *
		 * ## OPTIONS
		 *
		 * [--batch=<number>]
		 * : Number of attachments to process per query batch (default: 200).
		 *
		 * ## EXAMPLES
		 *
		 *     # Restore all missing local files from R2
		 *     wp r2 restore-local
		 *
		 *     # Restore with custom batch size
		 *     wp r2 restore-local --batch=500
		 *
		 * ## SAFETY
		 *
		 * - Only restores files for attachments with _r2_offloaded=true
		 * - Verifies object exists in R2 before download
		 * - Skips files that already exist locally
		 * - Never modifies attachment posts or metadata
		 * - Creates directories as needed
		 * - Continues processing on individual failures
		 *
		 * @since 1.0.0
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public static function cmd_restore_local(array $args, array $assoc_args): void {
			try {
				$limit = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 200;

				WP_CLI::log('Starting CF R2 local media restore...');

				// Get total count first for logging and safety guard.
				$count_query = new \WP_Query(
					[
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						// This meta_query is used intentionally for WP-CLI and batch processing.
						// It does not run on frontend requests, and performance impact is acceptable.
						'meta_query'     => [
							[
								'key'   => '_r2_offloaded',
								'value' => true,
							],
						],
					]
				);

				$total_found = ! empty($count_query->posts) ? count($count_query->posts) : 0;
				unset($count_query);

				WP_CLI::log(sprintf('Found %d attachments to process.', $total_found));

				if ($total_found === 0) {
					WP_CLI::success('No attachments found to process.');
					return;
				}

				$total_processed = 0;
				$total_restored  = 0;
				$total_skipped   = 0;
				$total_failed    = 0;
				$iteration       = 0;
				$page            = 1;

				while ($total_processed < $total_found) {
					$query = new \WP_Query(
						[
							'post_type'      => 'attachment',
							'post_status'    => 'inherit',
							'posts_per_page' => $limit,
							'paged'          => $page,
							'fields'         => 'ids',
							'orderby'        => 'ID',
							'order'          => 'ASC',
							// meta_query is used intentionally for CLI and batch processing.
							// This code does not run on frontend requests.
							'meta_query'     => [
								[
									'key'   => '_r2_offloaded',
									'value' => true,
								],
							],
						]
					);

					if (empty($query->posts)) {
						unset($query);
						break;
					}

					foreach ($query->posts as $attachment_id) {
						$attachment_id = (int) $attachment_id;
						$total_processed++;
						$iteration++;

						try {
							$result = r2mo_restore_local_for_attachment($attachment_id);
						} catch (\Throwable $e) {
							$total_failed++;
							WP_CLI::log(
								sprintf(
									'#%d - error (msg=%s)',
									$attachment_id,
									$e->getMessage()
								)
							);
							unset($result);
							continue;
						}

						if (! is_array($result)) {
							$total_failed++;
							WP_CLI::log(
								sprintf(
									'#%d - invalid restore result',
									$attachment_id
								)
							);
							unset($result);
							continue;
						}

						$total_restored += isset($result['restored']) ? (int) $result['restored'] : 0;
						$total_skipped  += isset($result['skipped']) ? (int) $result['skipped'] : 0;
						$total_failed   += isset($result['failed']) ? (int) $result['failed'] : 0;

						$status  = isset($result['status']) ? (string) $result['status'] : 'unknown';
						$message = isset($result['message']) ? (string) $result['message'] : '';

						$line = sprintf(
							'#%d - %s (restored=%d, skipped=%d, failed=%d, msg=%s)',
							$attachment_id,
							$status,
							isset($result['restored']) ? (int) $result['restored'] : 0,
							isset($result['skipped']) ? (int) $result['skipped'] : 0,
							isset($result['failed']) ? (int) $result['failed'] : 0,
							$message
						);

						WP_CLI::log($line);

						unset($result);

						if ($iteration % 10 === 0) {
							gc_collect_cycles();
						}
					}

					unset($query);

					$page++;

					// Safety guard: prevent infinite loops.
					if ($total_processed >= $total_found) {
						break;
					}

					sleep(1);
				}

				WP_CLI::log(sprintf('Processed %d attachments. Command finished.', $total_processed));
				WP_CLI::success(
					sprintf(
						'Local restore complete. Attachments processed: %d, Files restored: %d, Skipped: %d, Failed: %d',
						$total_processed,
						$total_restored,
						$total_skipped,
						$total_failed
					)
				);
			} catch (\Throwable $e) {
				WP_CLI::warning('CF R2 restore-local command aborted safely: ' . $e->getMessage());
			}
		}

		/**
		 * Purge all objects from CF R2 bucket.
		 *
		 * Permanently deletes all objects in your configured CF R2 bucket
		 * (or under the configured path prefix). This is a destructive operation
		 * that requires explicit confirmation.
		 *
		 * ## EXAMPLES
		 *
		 *     # Purge all objects (requires confirmation)
		 *     wp r2 purge
		 *
		 * ## SAFETY
		 *
		 * - Requires two confirmations: WP_CLI::confirm() and typing "PURGE"
		 * - Lists objects first to show what will be deleted
		 * - Never deletes local files or database records
		 * - Processes deletions in batches (max 1000 per request)
		 * - Fully wrapped in try/catch for safe termination
		 *
		 * ## WARNING
		 *
		 * This operation cannot be undone. All objects in the bucket (or under
		 * the path prefix) will be permanently deleted.
		 *
		 * @since 1.0.0
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public static function cmd_purge(array $args, array $assoc_args): void {
			try {
				WP_CLI::log('⚠️  WARNING: This will permanently delete ALL objects in your R2 bucket (or under the configured path prefix).');
				WP_CLI::log('This action cannot be undone.');

				// List objects first to show what will be deleted.
				$list_result = \R2MO\r2mo_list_r2_objects();

				if ($list_result['error'] !== '') {
					WP_CLI::error('Failed to list R2 objects: ' . $list_result['error']);
					return;
				}

				$total_found = $list_result['total'];

				if ($total_found === 0) {
					WP_CLI::success('No objects found in R2 bucket. Nothing to purge.');
					return;
				}

				WP_CLI::log(sprintf('Found %d objects to delete.', $total_found));

				// Require explicit confirmation.
				WP_CLI::confirm('Are you sure you want to delete ALL objects? Type "yes" to continue.');

				// Additional confirmation: require typing "PURGE".
				WP_CLI::line('Type "PURGE" to confirm: ');
				$confirm_text = trim((string) fgets(STDIN));

				if ($confirm_text !== 'PURGE') {
					WP_CLI::error('Purge aborted. Confirmation text did not match "PURGE".');
					return;
				}

				WP_CLI::log('Starting purge operation...');

				$result = r2mo_purge_r2_bucket();

				if ($result['success']) {
					WP_CLI::success($result['message']);
				} else {
					WP_CLI::warning($result['message']);

					if (! empty($result['errors'])) {
						foreach ($result['errors'] as $error) {
							WP_CLI::log('Error: ' . $error);
						}
					}
				}
			} catch (\Throwable $e) {
				WP_CLI::warning('CF R2 purge command aborted safely: ' . $e->getMessage());
			}
		}

		/**
		 * Bulk optimize existing images to WebP format.
		 *
		 * Converts existing JPEG and PNG images to WebP format and uploads them
		 * to CF R2. The WebP versions are served via CDN while original
		 * files are preserved locally as fallback.
		 *
		 * ## OPTIONS
		 *
		 * [--batch=<number>]
		 * : Number of attachments to process per query batch (default: 20).
		 *
		 * ## EXAMPLES
		 *
		 *     # Optimize all eligible images to WebP
		 *     wp r2 optimize-webp
		 *
		 *     # Process with custom batch size
		 *     wp r2 optimize-webp --batch=50
		 *
		 * ## SAFETY
		 *
		 * - Only processes image/jpeg and image/png attachments
		 * - Skips attachments that already have WebP versions
		 * - Preserves original files (never deletes them)
		 * - Never modifies attachment posts or metadata
		 * - Continues processing on individual failures
		 * - Requires wp_get_image_editor() with WebP support
		 *
		 * @since 1.0.0
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public static function cmd_optimize_webp(array $args, array $assoc_args): void {
			try {
				$limit = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 20;

				WP_CLI::log('Starting bulk WebP optimization for existing images...');

				// Get total count first for logging and safety guard.
				$count_query = new \WP_Query(
					[
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'post_mime_type' => ['image/jpeg', 'image/jpg', 'image/png'],
						// This meta_query is used intentionally for WP-CLI and batch processing.
						// It does not run on frontend requests, and performance impact is acceptable.
						'meta_query'     => [
							[
								'key'     => '_r2_webp_key',
								'compare' => 'NOT EXISTS',
							],
						],
					]
				);

				$total_found = ! empty($count_query->posts) ? count($count_query->posts) : 0;
				unset($count_query);

				WP_CLI::log(sprintf('Found %d images to optimize.', $total_found));

				if ($total_found === 0) {
					WP_CLI::success('No images found to optimize.');
					return;
				}

				$total_processed = 0;
				$total_optimized  = 0;
				$total_skipped    = 0;
				$total_failed     = 0;
				$iteration        = 0;
				$page             = 1;

				while ($total_processed < $total_found) {
					$query = new \WP_Query(
						[
							'post_type'      => 'attachment',
							'post_status'    => 'inherit',
							'posts_per_page' => $limit,
							'paged'          => $page,
							'fields'         => 'ids',
							'orderby'        => 'ID',
							'order'          => 'ASC',
							'post_mime_type' => ['image/jpeg', 'image/jpg', 'image/png'],
							// meta_query is used intentionally for CLI and batch processing.
							// This code does not run on frontend requests.
							'meta_query'     => [
								[
									'key'     => '_r2_webp_key',
									'compare' => 'NOT EXISTS',
								],
							],
						]
					);

					if (empty($query->posts)) {
						unset($query);
						break;
					}

					foreach ($query->posts as $attachment_id) {
						$attachment_id = (int) $attachment_id;
						$total_processed++;
						$iteration++;

						try {
							$result = r2mo_optimize_attachment_to_webp($attachment_id);
						} catch (\Throwable $e) {
							$total_failed++;
							WP_CLI::log(
								sprintf(
									'#%d - error (msg=%s)',
									$attachment_id,
									$e->getMessage()
								)
							);
							unset($result);
							continue;
						}

						if (! is_array($result)) {
							$total_failed++;
							WP_CLI::log(
								sprintf(
									'#%d - invalid optimization result',
									$attachment_id
								)
							);
							unset($result);
							continue;
						}

						$status  = isset($result['status']) ? (string) $result['status'] : 'unknown';
						$message = isset($result['message']) ? (string) $result['message'] : '';

						switch ($status) {
							case 'optimized':
								$total_optimized++;
								WP_CLI::log(sprintf('#%d - optimized (%s)', $attachment_id, $message));
								break;
							case 'failed':
								$total_failed++;
								WP_CLI::warning(sprintf('#%d - failed (%s)', $attachment_id, $message));
								break;
							default:
								$total_skipped++;
								WP_CLI::log(sprintf('#%d - skipped (%s)', $attachment_id, $message));
								break;
						}

						unset($result);

						if ($iteration % 10 === 0) {
							gc_collect_cycles();
						}
					}

					unset($query);

					$page++;

					// Safety guard: prevent infinite loops.
					if ($total_processed >= $total_found) {
						break;
					}

					sleep(1);
				}

				WP_CLI::log(sprintf('Processed %d images. Command finished.', $total_processed));
				WP_CLI::success(
					sprintf(
						'WebP optimization complete. Processed: %d, Optimized: %d, Skipped: %d, Failed: %d',
						$total_processed,
						$total_optimized,
						$total_skipped,
						$total_failed
					)
				);
			} catch (\Throwable $e) {
				WP_CLI::warning('CF R2 optimize-webp command aborted safely: ' . $e->getMessage());
			}
		}
	}

	Sync_CLI::register();
}

