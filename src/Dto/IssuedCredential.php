<?php declare(strict_types=1);

namespace KeyHarbor\Dto;

use InvalidArgumentException;
use KeyHarbor\Model\ApiCredential;

/**
 * Carries a persisted credential together with its one-time plaintext token.
 */
final class IssuedCredential {

	public function __construct(
		private readonly ApiCredential $credential,
		private readonly GeneratedCredentialToken $generatedToken
	) {
		if ($this->credential->getId() !== $this->generatedToken->getCredentialId()) {
			throw new InvalidArgumentException('Issued credential and token ids must match.');
		}
		if ($this->credential->getPublicId() !== $this->generatedToken->getPublicId()) {
			throw new InvalidArgumentException('Issued credential and token public ids must match.');
		}
		if ($this->credential->getSecretHash() !== $this->generatedToken->getSecretHash()) {
			throw new InvalidArgumentException('Issued credential and token hashes must match.');
		}
	}

	public function getCredential(): ApiCredential {
		return $this->credential;
	}

	public function getGeneratedToken(): GeneratedCredentialToken {
		return $this->generatedToken;
	}
}
