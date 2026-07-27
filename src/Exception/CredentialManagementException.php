<?php declare(strict_types=1);

namespace KeyHarbor\Exception;

use RuntimeException;

/**
 * Reports a stable credential-management failure category to displays.
 */
final class CredentialManagementException extends RuntimeException {

	public const NOT_AUTHENTICATED = 'not_authenticated';
	public const ACCESS_DENIED = 'access_denied';
	public const NOT_FOUND = 'not_found';
	public const REVOKED = 'revoked';
	public const NOT_REVOKED = 'not_revoked';
	public const EXPIRED = 'expired';
	public const UNSUPPORTED_MODE = 'unsupported_mode';

	public function __construct(
		private readonly string $reason,
		string $message
	) {
		parent::__construct($message);
	}

	public function getReason(): string {
		return $this->reason;
	}
}
