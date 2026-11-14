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

namespace Base3\KeyHarbor\Test\Security;

use InvalidArgumentException;
use Base3\KeyHarbor\Exception\CredentialHmacConfigurationException;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
use PHPUnit\Framework\TestCase;

final class CredentialSecretCipherEdgeCasesTest extends TestCase {

	public function testRejectsEmptySecretBeforeAccessingMasterKey(): void {
		$cipher = new CredentialSecretCipher(new CipherTestConfiguration(null), new CipherTestConfigValueResolver());

		$this->expectException(InvalidArgumentException::class);
		$cipher->encrypt('');
	}

	public function testEncryptingSameSecretUsesDistinctNonces(): void {
		$cipher = $this->cipher('a');
		$first = $cipher->encrypt('same-secret');
		$second = $cipher->encrypt('same-secret');

		self::assertNotSame($first->getNonce(), $second->getNonce());
		self::assertNotSame($first->getCiphertext(), $second->getCiphertext());
		self::assertSame('same-secret', $cipher->decrypt($first->getCiphertext(), $first->getNonce()));
		self::assertSame('same-secret', $cipher->decrypt($second->getCiphertext(), $second->getNonce()));
	}

	public function testDecryptRejectsEmptyCiphertext(): void {
		$cipher = $this->cipher('a');

		$this->expectException(CredentialHmacConfigurationException::class);
		$this->expectExceptionMessage('ciphertext is invalid');
		$cipher->decrypt('', base64_encode(str_repeat('b', 24)));
	}

	public function testDecryptRejectsNonBase64Ciphertext(): void {
		$cipher = $this->cipher('a');

		$this->expectException(CredentialHmacConfigurationException::class);
		$this->expectExceptionMessage('ciphertext is invalid');
		$cipher->decrypt('not+valid%%', base64_encode(str_repeat('b', 24)));
	}

	public function testDecryptRejectsInvalidNonceEncodingAndSize(): void {
		$cipher = $this->cipher('a');
		$valid = $cipher->encrypt('secret');

		foreach (['', '%%%%', base64_encode(str_repeat('b', 23)), base64_encode(str_repeat('b', 25))] as $nonce) {
			try {
				$cipher->decrypt($valid->getCiphertext(), $nonce);
				self::fail('Expected invalid nonce to be rejected.');
			} catch (CredentialHmacConfigurationException $exception) {
				self::assertStringContainsString('nonce is invalid', $exception->getMessage());
			}
		}
	}

	public function testDecryptRejectsTamperedCiphertext(): void {
		$cipher = $this->cipher('a');
		$valid = $cipher->encrypt('secret');
		$raw = base64_decode($valid->getCiphertext(), true);
		self::assertNotFalse($raw);
		$raw[0] = chr(ord($raw[0]) ^ 1);

		$this->expectException(CredentialHmacConfigurationException::class);
		$this->expectExceptionMessage('could not be decrypted');
		$cipher->decrypt(base64_encode($raw), $valid->getNonce());
	}

	public function testCiphertextCannotBeDecryptedWithAnotherMasterKey(): void {
		$first = $this->cipher('a');
		$second = $this->cipher('b');
		$encrypted = $first->encrypt('secret');

		$this->expectException(CredentialHmacConfigurationException::class);
		$this->expectExceptionMessage('could not be decrypted');
		$second->decrypt($encrypted->getCiphertext(), $encrypted->getNonce());
	}

	public function testFileModeConfigurationUsesConfigValueResolver(): void {
		$resolver = new CipherTestConfigValueResolver(base64_encode(str_repeat('f', 32)));
		$cipher = new CredentialSecretCipher(new CipherTestConfiguration([
			'mode' => 'file',
			'path' => '/keys/not-accessed-by-test'
		]), $resolver);
		$encrypted = $cipher->encrypt('secret');

		self::assertSame('secret', $cipher->decrypt($encrypted->getCiphertext(), $encrypted->getNonce()));
		self::assertSame(1, $resolver->getResolveCalls());
	}

	public function testAcceptsWhitespaceAroundBase64MasterKey(): void {
		$cipher = new CredentialSecretCipher(
			new CipherTestConfiguration(" \n" . base64_encode(str_repeat('a', 32)) . "\t"),
			new CipherTestConfigValueResolver()
		);
		$encrypted = $cipher->encrypt('binary' . "\0" . 'payload');

		self::assertSame('binary' . "\0" . 'payload', $cipher->decrypt($encrypted->getCiphertext(), $encrypted->getNonce()));
	}

	public function testRejectsUnsupportedMasterKeyTypes(): void {
		foreach ([null, 42, true, new \stdClass()] as $definition) {
			$cipher = new CredentialSecretCipher(new CipherTestConfiguration($definition), new CipherTestConfigValueResolver());
			try {
				$cipher->encrypt('secret');
				self::fail('Expected unsupported master key configuration to be rejected.');
			} catch (CredentialHmacConfigurationException $exception) {
				self::assertStringContainsString('base64 string or an env/file', $exception->getMessage());
			}
		}
	}

	private function cipher(string $keyCharacter): CredentialSecretCipher {
		return new CredentialSecretCipher(
			new CipherTestConfiguration(base64_encode(str_repeat($keyCharacter, 32))),
			new CipherTestConfigValueResolver()
		);
	}
}

require_once __DIR__ . '/CipherTestConfiguration.php';
require_once __DIR__ . '/CipherTestConfigValueResolver.php';
