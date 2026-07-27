<?php declare(strict_types=1);

namespace KeyHarbor\Exception;

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
