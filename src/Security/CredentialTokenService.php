<?php declare(strict_types=1);

namespace KeyHarbor\Security;

use InvalidArgumentException;
use KeyHarbor\Dto\GeneratedCredentialToken;
use KeyHarbor\Dto\ParsedCredentialToken;

/**
 * Generates, parses and verifies KeyHarbor bearer tokens.
 */
final class CredentialTokenService {

	private const TOKEN_PATTERN = '/^b3k_([a-f0-9]{20})_([A-Za-z0-9_-]{43})$/D';

	public function generate(): GeneratedCredentialToken {
		return $this->generateForCredential(bin2hex(random_bytes(16)));
	}

	public function generateForCredential(string $credentialId): GeneratedCredentialToken {
		if (!preg_match('/^[a-f0-9]{32}$/', $credentialId)) {
			throw new InvalidArgumentException(
				'Credential ids must contain 32 lowercase hexadecimal characters.'
			);
		}

		$publicId = bin2hex(random_bytes(10));
		$secret = $this->base64UrlEncode(random_bytes(32));
		$token = 'b3k_' . $publicId . '_' . $secret;

		return new GeneratedCredentialToken(
			$credentialId,
			$publicId,
			$secret,
			$token,
			$this->hashSecret($secret)
		);
	}

	public function parse(string $token): ?ParsedCredentialToken {
		if (!preg_match(self::TOKEN_PATTERN, trim($token), $matches)) {
			return null;
		}

		return new ParsedCredentialToken($matches[1], $matches[2]);
	}

	public function hashSecret(string $secret): string {
		return hash('sha256', $secret);
	}

	public function verifySecret(string $secret, string $secretHash): bool {
		if (!preg_match('/^[a-f0-9]{64}$/', $secretHash)) {
			return false;
		}

		return hash_equals($secretHash, $this->hashSecret($secret));
	}

	private function base64UrlEncode(string $value): string {
		return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	}
}
