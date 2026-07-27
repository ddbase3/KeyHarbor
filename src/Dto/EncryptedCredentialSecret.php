<?php declare(strict_types=1);

namespace KeyHarbor\Dto;

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
