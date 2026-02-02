<?php
/**
 * WP-CLI commands for syncing existing media to CF R2.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO\CLI;

use function R2MO\r2mo_sync_existing_batch;
use function R2MO\r2mo_delete_local_batch;
use function R2MO\r2mo_restore_local_batch;
use function R2MO\r2mo_purge_r2_bucket;
use function R2MO\r2mo_list_r2_objects;
use function R2MO\r2mo_generate_sync_report;
use function R2MO\r2mo_count_sync_existing_targets;
use function R2MO\r2mo_count_delete_local_targets;
use function R2MO\r2mo_count_restore_local_targets;
use WP_CLI;
use WP_CLI\Utils;

if (! defined('ABSPATH')) {
	exit;
}

if (defined('WP_CLI') && WP_CLI) {
	/**
		 * Sync existing media to CF R2 and manage safe local cleanup/restore.
	 */
	final class Sync_CLI {
		/**
		 * Guard to ensure SDK is available for CLI commands.
		 *
		 * @return bool
		 */
		private static function sdk_guard_or_warn(): bool {
			if (\R2MO\r2mo_is_sdk_available()) {
				return true;
			}

			WP_CLI::warning(\R2MO\r2mo_sdk_missing_message());
			return false;
		}

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
			WP_CLI::add_command('r2 report', [__CLASS__, 'cmd_report']);
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
			$progress = null;
			try {
				if (! self::sdk_guard_or_warn()) {
					return;
				}

				$limit = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 200;

				WP_CLI::log('Starting CF R2 existing media sync...');

				$total_found = r2mo_count_sync_existing_targets();
				WP_CLI::log(sprintf('Found %d attachments to process.', $total_found));

				if ($total_found === 0) {
					WP_CLI::success('No attachments found to process.');
					return;
				}

				$progress = Utils\make_progress_bar('Syncing media to R2', $total_found);
				$progress_ticks = 0;

				$total_processed = 0;
				$total_skipped   = 0;
				$total_failed    = 0;
				$page            = 1;

				while (true) {
					$result = r2mo_sync_existing_batch($limit, $page);
					$handled = $result['processed'] + $result['skipped'] + $result['failed'];

					if ($handled === 0) {
						break;
					}

					$total_processed += $result['processed'];
					$total_skipped   += $result['skipped'];
					$total_failed    += $result['failed'];

					$tick = min($handled, max(0, $total_found - $progress_ticks));
					if ($tick > 0) {
						$progress->tick($tick);
						$progress_ticks += $tick;
					}

					WP_CLI::log(
						sprintf(
							'Batch %d complete. Processed: %d, Skipped: %d, Failed: %d',
							$page,
							$result['processed'],
							$result['skipped'],
							$result['failed']
						)
					);

					$page++;
					sleep(1);
				}

				$progress->finish();

				WP_CLI::success(
					sprintf(
						'Sync complete. Processed: %d, Skipped: %d, Failed: %d',
						$total_processed,
						$total_skipped,
						$total_failed
					)
				);
			} catch (\Throwable $e) {
				if ($progress) {
					$progress->finish();
				}
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
			$progress = null;
			try {
				if (! self::sdk_guard_or_warn()) {
					return;
				}

				$limit = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 200;

				WP_CLI::log('Starting CF R2 safe local media cleanup...');

				$total_found = r2mo_count_delete_local_targets();
				WP_CLI::log(sprintf('Found %d attachments to process.', $total_found));

				if ($total_found === 0) {
					WP_CLI::success('No attachments found to process.');
					return;
				}

				$progress = Utils\make_progress_bar('Cleaning up local media', $total_found);
				$progress_ticks = 0;

				$total_processed = 0;
				$total_deleted   = 0;
				$total_skipped   = 0;
				$total_failed    = 0;

				while (true) {
					$result = r2mo_delete_local_batch($limit, 1);
					if ($result['processed'] === 0) {
						break;
					}

					$total_processed += $result['processed'];
					$total_deleted   += $result['deleted'];
					$total_skipped   += $result['skipped'];
					$total_failed    += $result['failed'];

					$tick = min($result['processed'], max(0, $total_found - $progress_ticks));
					if ($tick > 0) {
						$progress->tick($tick);
						$progress_ticks += $tick;
					}

					WP_CLI::log(
						sprintf(
							'Batch complete. Processed: %d, Deleted: %d, Skipped: %d, Failed: %d',
							$result['processed'],
							$result['deleted'],
							$result['skipped'],
							$result['failed']
						)
					);

					sleep(1);
				}

				$progress->finish();

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
				if ($progress) {
					$progress->finish();
				}
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
			$progress = null;
			try {
				if (! self::sdk_guard_or_warn()) {
					return;
				}

				$limit = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 200;

				WP_CLI::log('Starting CF R2 local media restore...');

				$total_found = r2mo_count_restore_local_targets();
				WP_CLI::log(sprintf('Found %d attachments to process.', $total_found));

				if ($total_found === 0) {
					WP_CLI::success('No attachments found to process.');
					return;
				}

				$progress = Utils\make_progress_bar('Restoring local media', $total_found);
				$progress_ticks = 0;

				$total_processed = 0;
				$total_restored  = 0;
				$total_skipped   = 0;
				$total_failed    = 0;

				while (true) {
					$result = r2mo_restore_local_batch($limit, 1);
					if ($result['processed'] === 0) {
						break;
					}

					$total_processed += $result['processed'];
					$total_restored  += $result['restored'];
					$total_skipped   += $result['skipped'];
					$total_failed    += $result['failed'];

					$tick = min($result['processed'], max(0, $total_found - $progress_ticks));
					if ($tick > 0) {
						$progress->tick($tick);
						$progress_ticks += $tick;
					}

					WP_CLI::log(
						sprintf(
							'Batch complete. Processed: %d, Restored: %d, Skipped: %d, Failed: %d',
							$result['processed'],
							$result['restored'],
							$result['skipped'],
							$result['failed']
						)
					);

					sleep(1);
				}

				$progress->finish();

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
				if ($progress) {
					$progress->finish();
				}
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
			$progress = null;
			try {
				if (! self::sdk_guard_or_warn()) {
					return;
				}

				WP_CLI::log('⚠️  WARNING: This will permanently delete ALL objects in your R2 bucket (or under the configured path prefix).');
				WP_CLI::log('This action cannot be undone.');

				// List objects first to show what will be deleted.
				$list_result = r2mo_list_r2_objects();

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

				$progress = Utils\make_progress_bar('Purging R2 bucket', $total_found);
				$progress_ticks = 0;

				$callback = static function (int $batch_count, int $deleted, int $failed) use (&$progress, &$progress_ticks, $total_found): void {
					$tick = min($batch_count, max(0, $total_found - $progress_ticks));
					if ($tick > 0) {
						$progress->tick($tick);
						$progress_ticks += $tick;
					}
				};

				WP_CLI::log('Starting purge operation...');

				$result = r2mo_purge_r2_bucket($callback, $list_result['keys']);

				$progress->finish();

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
				if ($progress) {
					$progress->finish();
				}
				WP_CLI::warning('CF R2 purge command aborted safely: ' . $e->getMessage());
			}
		}

		/**
		 * Generate a sync report (counts only).
		 *
		 * ## EXAMPLES
		 *
		 *     # Show current sync report
		 *     wp r2 report
		 *
		 * @since 1.0.0
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public static function cmd_report(array $args, array $assoc_args): void {
			$progress = null;
			try {
				if (! self::sdk_guard_or_warn()) {
					return;
				}

				$progress = Utils\make_progress_bar('Generating sync report', 3);
				$report = r2mo_generate_sync_report(static function () use ($progress): void {
					$progress->tick();
				});
				$progress->finish();

				if (! empty($report['errors'])) {
					foreach ($report['errors'] as $error) {
						WP_CLI::warning((string) $error);
					}
				}

				$items = [
					['metric' => 'Total attachments', 'value' => (int) $report['total_attachments']],
					['metric' => 'Total offloaded', 'value' => (int) $report['total_offloaded']],
					['metric' => 'Total R2 objects', 'value' => (int) $report['total_r2_objects']],
					['metric' => 'In sync', 'value' => (int) $report['in_sync']],
					['metric' => 'Missing in R2', 'value' => (int) $report['missing_in_r2']],
					['metric' => 'Orphaned in R2', 'value' => (int) $report['orphaned_in_r2']],
				];

				Utils\format_items('table', $items, ['metric', 'value']);
			} catch (\Throwable $e) {
				if ($progress) {
					$progress->finish();
				}
				WP_CLI::warning('CF R2 report command aborted safely: ' . $e->getMessage());
			}
		}

	}

	Sync_CLI::register();
}

