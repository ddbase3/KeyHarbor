<?php declare(strict_types=1);

namespace KeyHarbor\Test\Dto;

use InvalidArgumentException;
use KeyHarbor\Dto\EncryptedCredentialSecret;
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
