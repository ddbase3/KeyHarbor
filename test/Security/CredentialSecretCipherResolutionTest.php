<?php declare(strict_types=1);

namespace KeyHarbor\Test\Security;

use Base3\ConfigValue\Api\IConfigValueResolver;
use KeyHarbor\Exception\CredentialHmacConfigurationException;
use KeyHarbor\Security\CredentialSecretCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CredentialSecretCipherResolutionTest extends TestCase {

	public function testResolverFailurePreservesItsCauseWithoutPersistingAKey(): void {
		$failure = new RuntimeException('external key service unavailable');
		$resolver = $this->createStub(IConfigValueResolver::class);
		$resolver->method('resolve')->willReturnCallback(static function() use ($failure): never {
			throw $failure;
		});
		$cipher = new CredentialSecretCipher(new CipherTestConfiguration(['mode' => 'env', 'name' => 'MASTER_KEY']), $resolver);

		try {
			$cipher->encrypt('secret');
			self::fail('An unresolved master key must prevent encryption.');
		} catch (CredentialHmacConfigurationException $exception) {
			self::assertSame($failure, $exception->getPrevious());
			self::assertStringContainsString('could not be resolved', $exception->getMessage());
		}
	}

	public function testResolverResultMustBeAString(): void {
		foreach ([null, false, 123, ['key' => 'value']] as $value) {
			$resolver = $this->createStub(IConfigValueResolver::class);
			$resolver->method('resolve')->willReturn($value);
			$cipher = new CredentialSecretCipher(new CipherTestConfiguration(['mode' => 'file', 'path' => '/keys/master']), $resolver);

			try {
				$cipher->encrypt('secret');
				self::fail('Non-string master key resolution must be rejected.');
			} catch (CredentialHmacConfigurationException $exception) {
				self::assertStringContainsString('must resolve to a base64 string', $exception->getMessage());
			}
		}
	}

	public function testResolvedStringMustContainExactlyThirtyTwoDecodedBytes(): void {
		foreach (['', 'not-base64%%', base64_encode(str_repeat('a', 31)), base64_encode(str_repeat('a', 33))] as $value) {
			$resolver = $this->createStub(IConfigValueResolver::class);
			$resolver->method('resolve')->willReturn($value);
			$cipher = new CredentialSecretCipher(new CipherTestConfiguration(['mode' => 'env', 'name' => 'MASTER_KEY']), $resolver);

			try {
				$cipher->encrypt('secret');
				self::fail('Invalid resolved master key material must be rejected.');
			} catch (CredentialHmacConfigurationException $exception) {
				self::assertStringContainsString('32-byte key material', $exception->getMessage());
			}
		}
	}

	public function testConfigValueModeIsCaseInsensitiveAndOriginalDefinitionReachesResolver(): void {
		$definition = ['mode' => ' FILE ', 'path' => '/secrets/master.key'];
		$received = [];
		$resolver = $this->createStub(IConfigValueResolver::class);
		$resolver->method('resolve')->willReturnCallback(static function(array $actual) use (&$received): string {
			$received[] = $actual;
			return base64_encode(str_repeat('r', 32));
		});
		$cipher = new CredentialSecretCipher(new CipherTestConfiguration($definition), $resolver);
		$encrypted = $cipher->encrypt('first');

		self::assertSame('first', $cipher->decrypt($encrypted->getCiphertext(), $encrypted->getNonce()));
		self::assertSame([$definition], $received);
	}

	public function testResolvedMasterKeyIsCachedAfterFirstSuccessfulResolution(): void {
		$reads = 0;
		$resolver = $this->createStub(IConfigValueResolver::class);
		$resolver->method('resolve')->willReturnCallback(static function(array $definition) use (&$reads): string {
			self::assertSame('env', $definition['mode']);
			$reads++;
			return $reads === 1 ? base64_encode(str_repeat('m', 32)) : 'invalid-second-key';
		});
		$cipher = new CredentialSecretCipher(new CipherTestConfiguration(['mode' => 'env', 'name' => 'MASTER_KEY']), $resolver);
		$first = $cipher->encrypt('first');
		$second = $cipher->encrypt('second');

		self::assertSame('first', $cipher->decrypt($first->getCiphertext(), $first->getNonce()));
		self::assertSame('second', $cipher->decrypt($second->getCiphertext(), $second->getNonce()));
		self::assertSame(1, $reads);
	}

	public function testFailedResolutionDoesNotCacheAnInvalidMasterKey(): void {
		$reads = 0;
		$resolver = $this->createStub(IConfigValueResolver::class);
		$resolver->method('resolve')->willReturnCallback(static function() use (&$reads): string {
			$reads++;
			return $reads === 1 ? 'not-a-valid-key' : base64_encode(str_repeat('n', 32));
		});
		$cipher = new CredentialSecretCipher(new CipherTestConfiguration(['mode' => 'env', 'name' => 'MASTER_KEY']), $resolver);

		try {
			$cipher->encrypt('first');
			self::fail('Invalid key material must be rejected.');
		} catch (CredentialHmacConfigurationException $exception) {
			self::assertStringContainsString('32-byte key material', $exception->getMessage());
		}

		$encrypted = $cipher->encrypt('second');
		self::assertSame('second', $cipher->decrypt($encrypted->getCiphertext(), $encrypted->getNonce()));
		self::assertSame(2, $reads);
	}
}

require_once __DIR__ . '/CipherTestConfiguration.php';
