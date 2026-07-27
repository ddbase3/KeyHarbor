<?php declare(strict_types=1);

namespace KeyHarbor\Security;

use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use KeyHarbor\Dto\EncryptedCredentialSecret;
use KeyHarbor\Exception\CredentialHmacConfigurationException;
use Throwable;

/**
 * Encrypts HMAC secrets with sodium secretbox and a configured master key.
 */
final class CredentialSecretCipher {

	private const CONFIGURATION_GROUP = 'keyharbor';
	private const CONFIGURATION_KEY = 'hmac_master_key';
	private const KEY_BYTES = 32;
	private const NONCE_BYTES = 24;

	private ?string $masterKey = null;

	public function __construct(
		private readonly IConfiguration $configuration,
		private readonly IConfigValueResolver $configValueResolver
	) {}

	public function encrypt(string $secret): EncryptedCredentialSecret {
		if ($secret === '') {
			throw new \InvalidArgumentException('Credential HMAC secrets must not be empty.');
		}
		$this->assertSodiumAvailable();

		$nonce = random_bytes(self::NONCE_BYTES);
		$ciphertext = sodium_crypto_secretbox($secret, $nonce, $this->getMasterKey());

		return new EncryptedCredentialSecret(
			base64_encode($ciphertext),
			base64_encode($nonce)
		);
	}

	public function decrypt(string $ciphertext, string $nonce): string {
		$this->assertSodiumAvailable();
		$decodedCiphertext = base64_decode($ciphertext, true);
		$decodedNonce = base64_decode($nonce, true);

		if (!is_string($decodedCiphertext) || $decodedCiphertext === '') {
			throw new CredentialHmacConfigurationException('Credential HMAC ciphertext is invalid.');
		}
		if (!is_string($decodedNonce) || strlen($decodedNonce) !== self::NONCE_BYTES) {
			throw new CredentialHmacConfigurationException('Credential HMAC nonce is invalid.');
		}

		$secret = sodium_crypto_secretbox_open(
			$decodedCiphertext,
			$decodedNonce,
			$this->getMasterKey()
		);
		if (!is_string($secret)) {
			throw new CredentialHmacConfigurationException(
				'Credential HMAC secret could not be decrypted with the configured master key.'
			);
		}

		return $secret;
	}

	private function getMasterKey(): string {
		if ($this->masterKey !== null) {
			return $this->masterKey;
		}

		$definition = $this->configuration->getValue(
			self::CONFIGURATION_GROUP,
			self::CONFIGURATION_KEY,
			null
		);

		if (is_string($definition)) {
			$resolved = $definition;
		} elseif (is_array($definition)) {
			$resolved = $this->resolveConfigValueDefinition($definition);
		} else {
			throw new CredentialHmacConfigurationException(
				'KeyHarbor hmac_master_key must be a base64 string or an env/file ConfigValue definition.'
			);
		}

		$key = base64_decode(trim($resolved), true);
		if (!is_string($key) || strlen($key) !== self::KEY_BYTES) {
			throw new CredentialHmacConfigurationException(
				'KeyHarbor HMAC master key must be base64-encoded 32-byte key material.'
			);
		}

		$this->masterKey = $key;
		return $this->masterKey;
	}

	private function resolveConfigValueDefinition(array $definition): string {
		$mode = strtolower(trim((string)($definition['mode'] ?? '')));
		if (!in_array($mode, ['env', 'file'], true)) {
			throw new CredentialHmacConfigurationException(
				'KeyHarbor hmac_master_key ConfigValue definitions only support env or file modes.'
			);
		}

		try {
			$resolved = $this->configValueResolver->resolve($definition);
		} catch (Throwable $throwable) {
			throw new CredentialHmacConfigurationException(
				'KeyHarbor HMAC master key could not be resolved: ' . $throwable->getMessage(),
				0,
				$throwable
			);
		}

		if (!is_string($resolved)) {
			throw new CredentialHmacConfigurationException(
				'KeyHarbor HMAC master key must resolve to a base64 string.'
			);
		}

		return $resolved;
	}

	private function assertSodiumAvailable(): void {
		if (!function_exists('sodium_crypto_secretbox') ||
			!function_exists('sodium_crypto_secretbox_open')) {
			throw new CredentialHmacConfigurationException(
				'The sodium PHP extension is required for KeyHarbor HMAC credentials.'
			);
		}
	}
}
