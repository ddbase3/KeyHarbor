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

/**
 * Carries base64-encoded sodium secretbox material for persistence.
 */
final class EncryptedCredentialSecret {

	public function __construct(
		private readonly string $ciphertext,
		private readonly string $nonce
	) {
		if (trim($this->ciphertext) === '') {
			throw new InvalidArgumentException('Encrypted credential ciphertext must not be empty.');
		}
		if (trim($this->nonce) === '') {
			throw new InvalidArgumentException('Encrypted credential nonce must not be empty.');
		}
	}

	public function getCiphertext(): string {
		return $this->ciphertext;
	}

	public function getNonce(): string {
		return $this->nonce;
	}
}
