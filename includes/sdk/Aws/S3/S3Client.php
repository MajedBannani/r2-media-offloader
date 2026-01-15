<?php
/**
 * Minimal S3 client for Cloudflare R2 (Signature V4).
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace Aws\S3;

use Aws\Exception\AwsException;

if (! defined('ABSPATH')) {
	exit;
}

class S3Client {
	/**
	 * @var string
	 */
	private string $endpoint;

	/**
	 * @var string
	 */
	private string $region;

	/**
	 * @var string
	 */
	private string $access_key;

	/**
	 * @var string
	 */
	private string $secret_key;

	/**
	 * @var bool
	 */
	private bool $path_style = true;

	/**
	 * @param array<string, mixed> $config SDK config.
	 */
	public function __construct(array $config) {
		$this->endpoint  = isset($config['endpoint']) ? rtrim((string) $config['endpoint'], '/') : '';
		$this->region    = isset($config['region']) ? (string) $config['region'] : 'auto';
		$credentials     = isset($config['credentials']) && is_array($config['credentials']) ? $config['credentials'] : [];
		$this->access_key = isset($credentials['key']) ? (string) $credentials['key'] : '';
		$this->secret_key = isset($credentials['secret']) ? (string) $credentials['secret'] : '';
		$this->path_style = isset($config['use_path_style_endpoint']) ? (bool) $config['use_path_style_endpoint'] : true;

		if ($this->endpoint === '' || $this->access_key === '' || $this->secret_key === '') {
			throw new AwsException('Invalid S3 client configuration.', 0, 'Invalid S3 client configuration.');
		}
	}

	/**
	 * HEAD bucket.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>
	 */
	public function headBucket(array $params): array {
		$bucket = $this->require_bucket($params);
		$url    = $this->build_url($bucket);
		return $this->request('HEAD', $url, [], '', []);
	}

	/**
	 * PUT object.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>
	 */
	public function putObject(array $params): array {
		$bucket = $this->require_bucket($params);
		$key    = $this->require_key($params);
		$body   = isset($params['Body']) ? (string) $params['Body'] : '';

		$headers = [];
		if (isset($params['ContentType']) && is_string($params['ContentType']) && $params['ContentType'] !== '') {
			$headers['content-type'] = $params['ContentType'];
		}
		if (isset($params['ACL']) && is_string($params['ACL']) && $params['ACL'] !== '') {
			$headers['x-amz-acl'] = $params['ACL'];
		}

		$url = $this->build_url($bucket, $key);
		return $this->request('PUT', $url, [], $body, $headers);
	}

	/**
	 * HEAD object.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>
	 */
	public function headObject(array $params): array {
		$bucket = $this->require_bucket($params);
		$key    = $this->require_key($params);
		$url    = $this->build_url($bucket, $key);
		return $this->request('HEAD', $url, [], '', []);
	}

	/**
	 * GET object.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>
	 */
	public function getObject(array $params): array {
		$bucket = $this->require_bucket($params);
		$key    = $this->require_key($params);
		$url    = $this->build_url($bucket, $key);

		$options = [];
		if (isset($params['SaveAs']) && is_string($params['SaveAs']) && $params['SaveAs'] !== '') {
			$options['stream']   = true;
			$options['filename'] = $params['SaveAs'];
		}

		return $this->request('GET', $url, [], '', [], $options);
	}

	/**
	 * List objects (V2).
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>
	 */
	public function listObjectsV2(array $params): array {
		$bucket = $this->require_bucket($params);
		$query  = ['list-type' => '2'];

		if (isset($params['Prefix']) && is_string($params['Prefix']) && $params['Prefix'] !== '') {
			$query['prefix'] = $params['Prefix'];
		}
		if (isset($params['ContinuationToken']) && is_string($params['ContinuationToken']) && $params['ContinuationToken'] !== '') {
			$query['continuation-token'] = $params['ContinuationToken'];
		}

		$url    = $this->build_url($bucket);
		$result = $this->request('GET', $url, $query, '', []);

		if (! isset($result['body']) || ! is_string($result['body'])) {
			return ['Contents' => []];
		}

		return $this->parse_list_objects_response($result['body']);
	}

	/**
	 * Delete objects.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>
	 */
	public function deleteObjects(array $params): array {
		$bucket = $this->require_bucket($params);
		$objects = isset($params['Delete']['Objects']) && is_array($params['Delete']['Objects']) ? $params['Delete']['Objects'] : [];

		$xml = $this->build_delete_xml($objects);
		$url = $this->build_url($bucket);

		$result = $this->request('POST', $url, ['delete' => ''], $xml, ['content-type' => 'application/xml']);
		$body   = isset($result['body']) && is_string($result['body']) ? $result['body'] : '';

		return $this->parse_delete_objects_response($body);
	}

	/**
	 * Build URL for bucket/key.
	 */
	private function build_url(string $bucket, string $key = ''): string {
		$bucket = trim($bucket);
		$key    = ltrim($key, '/');

		if ($this->path_style) {
			$path = $bucket;
			if ($key !== '') {
				$path .= '/' . str_replace('%2F', '/', rawurlencode($key));
			}
			return $this->endpoint . '/' . $path;
		}

		$host = parse_url($this->endpoint, PHP_URL_HOST);
		$scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';
		$path = $key !== '' ? '/' . str_replace('%2F', '/', rawurlencode($key)) : '/';
		return $scheme . '://' . $bucket . '.' . $host . $path;
	}

	/**
	 * Require bucket parameter.
	 */
	private function require_bucket(array $params): string {
		$bucket = isset($params['Bucket']) ? (string) $params['Bucket'] : '';
		if ($bucket === '') {
			throw new AwsException('Missing bucket.', 0, 'Missing bucket.');
		}
		return $bucket;
	}

	/**
	 * Require key parameter.
	 */
	private function require_key(array $params): string {
		$key = isset($params['Key']) ? (string) $params['Key'] : '';
		if ($key === '') {
			throw new AwsException('Missing object key.', 0, 'Missing object key.');
		}
		return $key;
	}

	/**
	 * Execute signed request via WP HTTP API.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     URL.
	 * @param array<string, mixed> $query   Query params.
	 * @param string               $body    Body.
	 * @param array<string, string> $headers Extra headers.
	 * @param array<string, mixed> $options  HTTP options.
	 * @return array<string, mixed>
	 */
	private function request(string $method, string $url, array $query, string $body, array $headers, array $options = []): array {
		$method = strtoupper($method);
		$parsed = wp_parse_url($url);
		if (! is_array($parsed) || empty($parsed['host'])) {
			throw new AwsException('Invalid endpoint URL.', 0, 'Invalid endpoint URL.');
		}

		$canonical_uri = isset($parsed['path']) ? $parsed['path'] : '/';
		$host          = $parsed['host'];
		$scheme        = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';

		$canonical_query = $this->build_canonical_query($query);
		$request_url     = $scheme . '://' . $host . $canonical_uri;
		if ($canonical_query !== '') {
			$request_url .= '?' . $canonical_query;
		}

		$now        = gmdate('Ymd\THis\Z');
		$date_stamp = gmdate('Ymd');
		$payload_hash = hash('sha256', $body);

		$base_headers = [
			'host'                 => $host,
			'x-amz-date'           => $now,
			'x-amz-content-sha256' => $payload_hash,
		];

		foreach ($headers as $key => $value) {
			$base_headers[strtolower($key)] = $value;
		}

		$canonical_headers = $this->build_canonical_headers($base_headers);
		$signed_headers    = $this->build_signed_headers($base_headers);

		$canonical_request = implode(
			"\n",
			[
				$method,
				$canonical_uri,
				$canonical_query,
				$canonical_headers,
				$signed_headers,
				$payload_hash,
			]
		);

		$credential_scope = $date_stamp . '/' . $this->region . '/s3/aws4_request';
		$string_to_sign   = implode(
			"\n",
			[
				'AWS4-HMAC-SHA256',
				$now,
				$credential_scope,
				hash('sha256', $canonical_request),
			]
		);

		$signing_key = $this->get_signing_key($date_stamp);
		$signature   = hash_hmac('sha256', $string_to_sign, $signing_key);

		$authorization = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$this->access_key,
			$credential_scope,
			$signed_headers,
			$signature
		);

		$base_headers['authorization'] = $authorization;

		$request_headers = [];
		foreach ($base_headers as $key => $value) {
			$request_headers[$key] = $value;
		}

		$args = [
			'method'  => $method,
			'headers' => $request_headers,
			'body'    => $body,
			'timeout' => 30,
		];

		if (! empty($options)) {
			$args = array_merge($args, $options);
		}

		$response = wp_remote_request($request_url, $args);
		if (is_wp_error($response)) {
			throw new AwsException($response->get_error_message(), 0, $response->get_error_message());
		}

		$status = wp_remote_retrieve_response_code($response);
		$resp_body = wp_remote_retrieve_body($response);

		if ($status < 200 || $status >= 300) {
			$message = $this->parse_error_message($resp_body);
			$label = $message !== '' ? $message : 'Request failed.';
			throw new AwsException($label, $status, $message);
		}

		return [
			'status' => $status,
			'body'   => $resp_body,
		];
	}

	/**
	 * Build canonical query string.
	 *
	 * @param array<string, mixed> $query Query parameters.
	 */
	private function build_canonical_query(array $query): string {
		if (empty($query)) {
			return '';
		}

		$parts = [];
		foreach ($query as $key => $value) {
			$encoded_key = rawurlencode((string) $key);
			if ($value === '' || $value === null) {
				$parts[] = $encoded_key . '=';
			} else {
				$parts[] = $encoded_key . '=' . rawurlencode((string) $value);
			}
		}

		sort($parts, SORT_STRING);
		return implode('&', $parts);
	}

	/**
	 * Build canonical headers string.
	 *
	 * @param array<string, string> $headers Headers.
	 */
	private function build_canonical_headers(array $headers): string {
		ksort($headers);
		$lines = [];
		foreach ($headers as $key => $value) {
			$lines[] = strtolower($key) . ':' . trim((string) $value);
		}
		return implode("\n", $lines) . "\n";
	}

	/**
	 * Build signed headers list.
	 *
	 * @param array<string, string> $headers Headers.
	 */
	private function build_signed_headers(array $headers): string {
		$keys = array_keys($headers);
		$keys = array_map('strtolower', $keys);
		sort($keys, SORT_STRING);
		return implode(';', $keys);
	}

	/**
	 * Derive signing key.
	 */
	private function get_signing_key(string $date_stamp): string {
		$k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $this->secret_key, true);
		$k_region = hash_hmac('sha256', $this->region, $k_date, true);
		$k_service = hash_hmac('sha256', 's3', $k_region, true);
		return hash_hmac('sha256', 'aws4_request', $k_service, true);
	}

	/**
	 * Parse error message from XML.
	 */
	private function parse_error_message(string $body): string {
		if ($body === '') {
			return '';
		}

		if (preg_match('/<Message>([^<]+)<\\/Message>/', $body, $matches)) {
			return html_entity_decode($matches[1], ENT_QUOTES);
		}

		return '';
	}

	/**
	 * Parse list objects response XML.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_list_objects_response(string $body): array {
		$result = [
			'Contents' => [],
		];

		if ($body === '') {
			return $result;
		}

		if (! preg_match_all('/<Contents>.*?<Key>(.*?)<\\/Key>.*?<\\/Contents>/s', $body, $matches)) {
			return $result;
		}

		foreach ($matches[1] as $key) {
			$result['Contents'][] = ['Key' => html_entity_decode($key, ENT_QUOTES)];
		}

		if (preg_match('/<NextContinuationToken>(.*?)<\\/NextContinuationToken>/', $body, $token_match)) {
			$result['NextContinuationToken'] = html_entity_decode($token_match[1], ENT_QUOTES);
		}

		return $result;
	}

	/**
	 * Build DeleteObjects XML payload.
	 *
	 * @param array<int, array<string, string>> $objects Objects list.
	 */
	private function build_delete_xml(array $objects): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><Delete>';
		foreach ($objects as $object) {
			if (! isset($object['Key'])) {
				continue;
			}
			$key = (string) $object['Key'];
			if ($key === '') {
				continue;
			}
			$xml .= '<Object><Key>' . esc_html($key) . '</Key></Object>';
		}
		$xml .= '</Delete>';
		return $xml;
	}

	/**
	 * Parse DeleteObjects response.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_delete_objects_response(string $body): array {
		$deleted = [];
		$errors  = [];

		if ($body !== '') {
			if (preg_match_all('/<Deleted>.*?<Key>(.*?)<\\/Key>.*?<\\/Deleted>/s', $body, $deleted_matches)) {
				foreach ($deleted_matches[1] as $key) {
					$deleted[] = ['Key' => html_entity_decode($key, ENT_QUOTES)];
				}
			}

			if (preg_match_all('/<Error>.*?<Key>(.*?)<\\/Key>.*?<Message>(.*?)<\\/Message>.*?<\\/Error>/s', $body, $error_matches, PREG_SET_ORDER)) {
				foreach ($error_matches as $match) {
					$errors[] = [
						'Key'     => html_entity_decode($match[1], ENT_QUOTES),
						'Message' => html_entity_decode($match[2], ENT_QUOTES),
					];
				}
			}
		}

		return [
			'Deleted' => $deleted,
			'Errors'  => $errors,
		];
	}
}
