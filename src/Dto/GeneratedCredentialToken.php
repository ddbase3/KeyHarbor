<?php declare(strict_types=1);

namespace KeyHarbor\Dto;

use InvalidArgumentException;

/**
 * Carries a newly generated credential token.
 *
 * The plaintext token and secret must only be exposed once during creation or
 * rotation. Persistence uses the credential id, public id and secret hash.
 */
final class GeneratedCredentialToken {

	public function __construct(
		private readonly string $credentialId,
		private readonly string $publicId,
		private readonly string $secret,
		private readonly string $token,
		private readonly string $secretHash
	) {
		if (!preg_match('/^[a-f0-9]{32}$/', $this->credentialId)) {
			throw new InvalidArgumentException('Credential ids must contain 32 lowercase hexadecimal characters.');
		}
		if (!preg_match('/^[a-f0-9]{20}$/', $this->publicId)) {
			throw new InvalidArgumentException('Credential public ids must contain 20 lowercase hexadecimal characters.');
		}
		if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $this->secret)) {
			throw new InvalidArgumentException('Credential secrets must be 43-character base64url strings.');
		}
		if ($this->token !== 'b3k_' . $this->publicId . '_' . $this->secret) {
			throw new InvalidArgumentException('Credential token does not match its public id and secret.');
		}
		if (!preg_match('/^[a-f0-9]{64}$/', $this->secretHash)) {
			throw new InvalidArgumentException('Credential secret hashes must contain 64 lowercase hexadecimal characters.');
		}
	}

	public function getCredentialId(): string {
		return $this->credentialId;
	}

	public function getPublicId(): string {
		return $this->publicId;
	}

	public function getSecret(): string {
		return $this->secret;
	}

	public function getToken(): string {
		return $this->token;
	}

	public function getSecretHash(): string {
		return $this->secretHash;
	}
}
