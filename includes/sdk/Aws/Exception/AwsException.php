<?php
/**
 * Minimal AwsException implementation for bundled SDK.
 *
 * @package R2MO
 */

declare(strict_types=1);

namespace Aws\Exception;

if (! defined('ABSPATH')) {
	exit;
}

class AwsException extends \RuntimeException {
	/**
	 * @var string
	 */
	private string $aws_error_message = '';

	/**
	 * @param string $message Message.
	 * @param int    $code    Code.
	 * @param string $aws_error_message AWS error message.
	 */
	public function __construct(string $message = '', int $code = 0, string $aws_error_message = '') {
		parent::__construct($message, $code);
		$this->aws_error_message = $aws_error_message;
	}

	/**
	 * Return the AWS error message when available.
	 *
	 * @return string
	 */
	public function getAwsErrorMessage(): string {
		return $this->aws_error_message;
	}
}
