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

use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\ConfigValue\Api\IConfigValueResolver;
use Base3\KeyHarbor\Exception\CredentialHmacConfigurationException;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
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

require_once __DIR__ . '/CipherTestConfiguration.php';

require_once __DIR__ . '/CipherTestConfigValueResolver.php';
