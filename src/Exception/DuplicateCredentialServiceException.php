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
 * Raised when multiple providers expose the same credential service id.
 */
final class DuplicateCredentialServiceException extends RuntimeException {

	public function __construct(
		private readonly string $serviceId,
		private readonly string $firstProviderName,
		private readonly string $secondProviderName
	) {
		parent::__construct(
			'Credential service id "' . $this->serviceId . '" is provided by both "' .
			$this->firstProviderName . '" and "' . $this->secondProviderName . '".'
		);
	}

	public function getServiceId(): string {
		return $this->serviceId;
	}

	public function getFirstProviderName(): string {
		return $this->firstProviderName;
	}

	public function getSecondProviderName(): string {
		return $this->secondProviderName;
	}
}
