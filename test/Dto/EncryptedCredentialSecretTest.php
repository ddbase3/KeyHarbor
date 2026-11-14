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

namespace Base3\KeyHarbor\Test\Dto;

use InvalidArgumentException;
use Base3\KeyHarbor\Dto\EncryptedCredentialSecret;
use PHPUnit\Framework\TestCase;

final class EncryptedCredentialSecretTest extends TestCase {

	public function testPreservesOpaqueCiphertextAndNonceExactly(): void {
		$ciphertext = base64_encode("\x00secret\xff");
		$nonce = base64_encode(str_repeat("\x01", 24));
		$secret = new EncryptedCredentialSecret($ciphertext, $nonce);
		self::assertSame($ciphertext, $secret->getCiphertext());
		self::assertSame($nonce, $secret->getNonce());
	}

	public function testDoesNotSilentlyTrimNonEmptyValues(): void {
		$secret = new EncryptedCredentialSecret(' cipher ', ' nonce ');
		self::assertSame(' cipher ', $secret->getCiphertext());
		self::assertSame(' nonce ', $secret->getNonce());
	}

	public function testRejectsBlankCiphertext(): void {
		foreach (['', ' ', "\n\t"] as $ciphertext) {
			try {
				new EncryptedCredentialSecret($ciphertext, 'nonce');
				self::fail('Blank ciphertext must be rejected.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('ciphertext', $exception->getMessage());
			}
		}
	}

	public function testRejectsBlankNonce(): void {
		foreach (['', ' ', "\n\t"] as $nonce) {
			try {
				new EncryptedCredentialSecret('ciphertext', $nonce);
				self::fail('Blank nonce must be rejected.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('nonce', $exception->getMessage());
			}
		}
	}
}
