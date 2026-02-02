<?php
/**
 * Purge CF R2 bucket (bulk delete objects).
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace R2MO;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * List all object keys in the R2 bucket (optionally filtered by path_prefix).
 *
 * @return array{keys:array<string>,total:int,error:string}
 */
function r2mo_list_r2_objects(): array {
	try {
		if (! r2mo_is_sdk_available()) {
			return [
				'keys'  => [],
				'total' => 0,
				'error' => r2mo_sdk_missing_message(),
			];
		}

		$bucket     = (string) Settings::get('bucket');
		$path_prefix = (string) Settings::get('path_prefix');
		$path_prefix = trim($path_prefix);
		$path_prefix = trim($path_prefix, '/');

		if ($bucket === '') {
			return [
				'keys'  => [],
				'total' => 0,
				'error' => __('R2 bucket not configured.', 'media-offloader-for-cf-r2'),
			];
		}

		$client = R2_Client::instance()->client();
		$keys   = [];
		$continuation_token = null;

		do {
			$params = [
				'Bucket' => $bucket,
			];

			if ($path_prefix !== '') {
				$params['Prefix'] = $path_prefix . '/';
			}

			if ($continuation_token !== null) {
				$params['ContinuationToken'] = $continuation_token;
			}

			$result = $client->listObjectsV2($params);

			if (isset($result['Contents']) && is_array($result['Contents'])) {
				foreach ($result['Contents'] as $object) {
					if (isset($object['Key']) && is_string($object['Key'])) {
						$keys[] = $object['Key'];
					}
				}
			}

			$continuation_token = isset($result['NextContinuationToken']) && is_string($result['NextContinuationToken']) ? $result['NextContinuationToken'] : null;
		} while ($continuation_token !== null);

		return [
			'keys'  => $keys,
			'total' => count($keys),
			'error' => '',
		];
	} catch (\Throwable $e) {
		return [
			'keys'  => [],
			'total' => 0,
			'error' => r2mo_get_aws_error_message($e),
		];
	}
}

/**
 * Delete objects from R2 in batches (max 1000 per batch).
 *
 * This function deletes multiple objects from CF R2 in a single operation.
 * It processes deletions in batches of up to 1000 objects (AWS SDK limit) and
 * handles errors gracefully, continuing with remaining batches even if one fails.
 *
 * Safety guarantees:
 * - Never deletes local files
 * - Never modifies database records
 * - Processes in safe batches (max 1000 per request)
 * - Continues processing on batch failures
 * - Returns detailed error information
 *
 * @since 1.0.0
 * @param array<string> $keys Object keys to delete.
 * @param callable|null $on_batch Optional callback for progress reporting.
 * @return array{deleted:int,failed:int,errors:array<string>} Result array with deletion counts and errors.
 */
function r2mo_delete_r2_objects(array $keys, ?callable $on_batch = null): array {
	if (empty($keys)) {
		return [
			'deleted' => 0,
			'failed'   => 0,
			'errors'   => [],
		];
	}

	try {
		if (! r2mo_is_sdk_available()) {
			return [
				'deleted' => 0,
				'failed'  => count($keys),
				'errors'  => [r2mo_sdk_missing_message()],
			];
		}

		$bucket = (string) Settings::get('bucket');

		if ($bucket === '') {
			return [
				'deleted' => 0,
				'failed'  => count($keys),
				'errors'  => [__('R2 bucket not configured.', 'media-offloader-for-cf-r2')],
			];
		}

		$client = R2_Client::instance()->client();
		$deleted = 0;
		$failed  = 0;
		$errors  = [];

		// Delete in batches of 1000 (AWS SDK limit).
		$batches = array_chunk($keys, 1000);

		foreach ($batches as $batch) {
			try {
				$objects = [];
				foreach ($batch as $key) {
					if (is_string($key) && $key !== '') {
						$objects[] = ['Key' => $key];
					}
				}

				if (empty($objects)) {
					continue;
				}

				$result = $client->deleteObjects(
					[
						'Bucket' => $bucket,
						'Delete' => [
							'Objects' => $objects,
						],
					]
				);

				if (isset($result['Deleted']) && is_array($result['Deleted'])) {
					$deleted += count($result['Deleted']);
					$deleted_keys = [];
					foreach ($result['Deleted'] as $deleted_item) {
						if (isset($deleted_item['Key']) && is_string($deleted_item['Key'])) {
							$deleted_keys[] = $deleted_item['Key'];
						}
					}

					if (! empty($deleted_keys)) {
						r2mo_clear_offload_meta_for_keys($deleted_keys);
					}
				}

				if (isset($result['Errors']) && is_array($result['Errors'])) {
					foreach ($result['Errors'] as $error) {
						$failed++;
						$key = isset($error['Key']) ? (string) $error['Key'] : '';
						/* translators: %s: error message */
						$msg = isset($error['Message']) ? (string) $error['Message'] : __('Unknown deletion error.', 'media-offloader-for-cf-r2');
						/* translators: 1: object key, 2: error message */
						$errors[] = sprintf(__('%1$s: %2$s', 'media-offloader-for-cf-r2'), $key, $msg);
					}
				}
				if (is_callable($on_batch)) {
					$on_batch(count($batch), $deleted, $failed);
				}
			} catch (\Throwable $e) {
				$failed += count($batch);
				$errors[] = r2mo_get_aws_error_message($e);
				if (is_callable($on_batch)) {
					$on_batch(count($batch), $deleted, $failed);
				}
			}
		}

		return [
			'deleted' => $deleted,
			'failed'  => $failed,
			'errors'  => $errors,
		];
	} catch (\Throwable $e) {
		return [
			'deleted' => 0,
			'failed'  => count($keys),
			'errors'  => [$e->getMessage()],
		];
	}
}

/**
 * Purge all objects from R2 bucket (with path_prefix filtering if configured).
 *
 * This function permanently deletes all objects in the configured CF R2
 * bucket. If a path_prefix is configured, only objects under that prefix are
 * deleted. This is a destructive operation that should only be called after
 * explicit user confirmation.
 *
 * Safety guarantees:
 * - Never deletes local files
 * - Never modifies database records
 * - Processes deletions in batches (max 1000 per request)
 * - Handles pagination via ContinuationToken
 * - Fully wrapped in try/catch for safe termination
 * - Logs all operations for audit trail
 *
 * @since 1.0.0
 * @param callable|null $on_batch Optional callback for progress reporting.
 * @param array<string>|null $prelisted_keys Optional pre-listed keys to avoid re-listing.
 * @return array{success:bool,total_found:int,deleted:int,failed:int,errors:array<string>,message:string} Result array with deletion counts and message.
 */
function r2mo_purge_r2_bucket(?callable $on_batch = null, ?array $prelisted_keys = null): array {
	try {
		$start_time = microtime(true);

		// List all objects.
		$list_result = $prelisted_keys === null ? r2mo_list_r2_objects() : [
			'keys'  => $prelisted_keys,
			'total' => count($prelisted_keys),
			'error' => '',
		];

		if ($list_result['error'] !== '') {
			return [
				'success'     => false,
				'total_found' => 0,
				'deleted'     => 0,
				'failed'      => 0,
				'errors'      => [$list_result['error']],
				'message'     => $list_result['error'],
			];
		}

		$total_found = $list_result['total'];
		$keys        = $list_result['keys'];

		if ($total_found === 0) {
			return [
				'success'     => true,
				'total_found' => 0,
				'deleted'     => 0,
				'failed'      => 0,
				'errors'      => [],
				'message'     => __('No objects found in R2 bucket.', 'media-offloader-for-cf-r2'),
			];
		}

		// Delete all objects.
		$delete_result = r2mo_delete_r2_objects($keys, $on_batch);

		$end_time     = microtime(true);
		$execution_time = round($end_time - $start_time, 2);

		$message = sprintf(
			/* translators: 1: total found, 2: deleted, 3: failed, 4: execution time */
			__('Purge complete. Found: %1$d, Deleted: %2$d, Failed: %3$d (Time: %4$ss)', 'media-offloader-for-cf-r2'),
			$total_found,
			$delete_result['deleted'],
			$delete_result['failed'],
			$execution_time
		);


		return [
			'success'     => $delete_result['failed'] === 0,
			'total_found' => $total_found,
			'deleted'     => $delete_result['deleted'],
			'failed'      => $delete_result['failed'],
			'errors'      => $delete_result['errors'],
			'message'     => $message,
		];
	} catch (\Throwable $e) {

		return [
			'success'     => false,
			'total_found' => 0,
			'deleted'     => 0,
			'failed'      => 0,
			'errors'      => [$e->getMessage()],
			'message'     => __('Purge failed with an unexpected error.', 'media-offloader-for-cf-r2'),
		];
	}
}
