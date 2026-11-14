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

namespace Base3\KeyHarbor\Dto;

use InvalidArgumentException;
use Base3\KeyHarbor\Model\ApiCredential;

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
