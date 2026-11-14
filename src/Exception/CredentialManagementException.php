<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of KeyHarbor for BASE3 Framework.
 *
 * KeyHarbor manages durable API credentials for BASE3 applications.
 * It provides secure credential lifecycle and encryption profiles.
 * Authorization and credential subjects follow existing shared contracts.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 * https://github.com/ddbase3/KeyHarbor
 **********************************************************************/

namespace Base3\KeyHarbor\Exception;

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
