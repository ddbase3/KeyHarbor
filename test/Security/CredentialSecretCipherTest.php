<?php declare(strict_types=1);

namespace KeyHarbor\Test\Security;

use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use KeyHarbor\Exception\CredentialHmacConfigurationException;
use KeyHarbor\Security\CredentialSecretCipher;
use PHPUnit\Framework\TestCase;

final class CredentialSecretCipherTest extends TestCase {

	public function testEncryptsAndDecryptsWithDirectConfigurationValue(): void {
		$resolver = new CipherTestConfigValueResolver();
		$cipher = new CredentialSecretCipher(
			new CipherTestConfiguration(base64_encode(str_repeat('d', 32))),
			$resolver
		);

		$encrypted = $cipher->encrypt('credential-secret');

		self::assertSame(
			'credential-secret',
			$cipher->decrypt($encrypted->getCiphertext(), $encrypted->getNonce())
		);
		self::assertSame(0, $resolver->getResolveCalls());
	}

	public function testKeepsEnvAndFileConfigValueCompatibility(): void {
		$resolver = new CipherTestConfigValueResolver(base64_encode(str_repeat('e', 32)));
		$cipher = new CredentialSecretCipher(
			new CipherTestConfiguration([
				'mode' => 'env',
				'name' => 'KEYHARBOR_TEST_KEY'
			]),
			$resolver
		);

		$encrypted = $cipher->encrypt('credential-secret');

		self::assertSame(
			'credential-secret',
			$cipher->decrypt($encrypted->getCiphertext(), $encrypted->getNonce())
		);
		self::assertSame(1, $resolver->getResolveCalls());
	}

	public function testRejectsInvalidDirectConfigurationValue(): void {
		$cipher = new CredentialSecretCipher(
			new CipherTestConfiguration('not-a-valid-key'),
			new CipherTestConfigValueResolver()
		);

		$this->expectException(CredentialHmacConfigurationException::class);
		$this->expectExceptionMessage('base64-encoded 32-byte key material');
		$cipher->encrypt('credential-secret');
	}

	public function testRejectsUnsupportedConfigValueMode(): void {
		$cipher = new CredentialSecretCipher(
			new CipherTestConfiguration([
				'mode' => 'fixed',
				'value' => base64_encode(str_repeat('f', 32))
			]),
			new CipherTestConfigValueResolver()
		);

		$this->expectException(CredentialHmacConfigurationException::class);
		$this->expectExceptionMessage('only support env or file modes');
		$cipher->encrypt('credential-secret');
	}
}

final class CipherTestConfiguration implements IConfiguration {

	public function __construct(
		private readonly mixed $masterKey
	) {}

	public function get($configuration = '') { return []; }
	public function set($data, $configuration = ''): void {}
	public function save(): void {}
	public function getGroup(string $group, array $default = []): array { return $default; }
	public function getValue(string $group, string $key, $default = null) {
		return $group === 'keyharbor' && $key === 'hmac_master_key'
			? $this->masterKey
			: $default;
	}
	public function getString(string $group, string $key, string $default = ''): string { return $default; }
	public function getInt(string $group, string $key, int $default = 0): int { return $default; }
	public function getFloat(string $group, string $key, float $default = 0.0): float { return $default; }
	public function getBool(string $group, string $key, bool $default = false): bool { return $default; }
	public function getArray(string $group, string $key, array $default = []): array { return $default; }
	public function hasGroup(string $group): bool { return $group === 'keyharbor'; }
	public function hasValue(string $group, string $key): bool {
		return $group === 'keyharbor' && $key === 'hmac_master_key';
	}
	public function setValue(string $group, string $key, $value): void {}
	public function setGroup(string $group, array $values, bool $merge = true): void {}
	public function setMany(array $data, bool $merge = true): void {}
	public function removeGroup(string $group): void {}
	public function removeValue(string $group, string $key): void {}
	public function isDirty(): bool { return false; }
	public function saveIfDirty(): bool { return true; }
	public function trySave(): bool { return true; }
	public function reload(): void {}
	public function persistValue(string $group, string $key, $value): bool { return true; }
}

final class CipherTestConfigValueResolver implements IConfigValueResolver {

	private int $resolveCalls = 0;

	public function __construct(
		private readonly string $resolvedValue = ''
	) {}

	public static function getName(): string {
		return 'ciphertestconfigvalueresolver';
	}

	public function resolve(array|string|int|float|bool|null $config): mixed {
		$this->resolveCalls++;
		return $this->resolvedValue;
	}

	public function getResolveCalls(): int {
		return $this->resolveCalls;
	}

	public function getModes(): array { return []; }
	public function getModeSchema(string $mode): ?array { return null; }
	public function getModeSchemas(): array { return []; }
	public function getModeResolverNames(): array { return []; }
}
