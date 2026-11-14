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
use Base3\KeyHarbor\Dto\GeneratedCredentialToken;
use PHPUnit\Framework\TestCase;

final class GeneratedCredentialTokenTest extends TestCase {

	public function testPreservesAllGeneratedTokenComponents(): void {
		$token = $this->createToken();
		self::assertSame(str_repeat('a', 32), $token->getCredentialId());
		self::assertSame(str_repeat('b', 20), $token->getPublicId());
		self::assertSame(str_repeat('C', 43), $token->getSecret());
		self::assertSame('b3k_' . str_repeat('b', 20) . '_' . str_repeat('C', 43), $token->getToken());
		self::assertSame(str_repeat('d', 64), $token->getSecretHash());
	}

	public function testRejectsInvalidCredentialIds(): void {
		foreach (['', str_repeat('a', 31), str_repeat('a', 33), str_repeat('A', 32), str_repeat('g', 32)] as $id) {
			$this->assertRejected(['credentialId' => $id]);
		}
	}

	public function testRejectsInvalidPublicIds(): void {
		foreach (['', str_repeat('b', 19), str_repeat('b', 21), str_repeat('B', 20), str_repeat('z', 20)] as $publicId) {
			$this->assertRejected(['publicId' => $publicId]);
		}
	}

	public function testRejectsInvalidBase64urlSecrets(): void {
		foreach (['', str_repeat('C', 42), str_repeat('C', 44), str_repeat('+', 43), str_repeat('/', 43), str_repeat('=', 43)] as $secret) {
			$this->assertRejected(['secret' => $secret]);
		}
	}

	public function testRejectsTokenMismatches(): void {
		foreach (['', 'b3k_' . str_repeat('b', 20) . '_' . str_repeat('X', 43), 'b3k_' . str_repeat('c', 20) . '_' . str_repeat('C', 43), 'bearer_' . str_repeat('b', 20) . '_' . str_repeat('C', 43)] as $value) {
			$this->assertRejected(['token' => $value]);
		}
	}

	public function testRejectsInvalidSecretHashes(): void {
		foreach (['', str_repeat('d', 63), str_repeat('d', 65), str_repeat('D', 64), str_repeat('g', 64)] as $hash) {
			$this->assertRejected(['hash' => $hash]);
		}
	}

	public function testAcceptsBase64urlAlphabetIncludingUnderscoreAndHyphen(): void {
		$secret = str_repeat('A', 39) . 'a0_-';
		$generated = $this->createToken([
			'secret' => $secret,
			'token' => 'b3k_' . str_repeat('b', 20) . '_' . $secret
		]);
		self::assertSame($secret, $generated->getSecret());
	}

	private function assertRejected(array $changes): void {
		try {
			$this->createToken($changes);
			self::fail('Invalid generated token was accepted.');
		} catch (InvalidArgumentException $exception) {
			self::assertNotSame('', $exception->getMessage());
		}
	}

	private function createToken(array $changes = []): GeneratedCredentialToken {
		$data = array_replace([
			'credentialId' => str_repeat('a', 32),
			'publicId' => str_repeat('b', 20),
			'secret' => str_repeat('C', 43),
			'token' => 'b3k_' . str_repeat('b', 20) . '_' . str_repeat('C', 43),
			'hash' => str_repeat('d', 64)
		], $changes);
		return new GeneratedCredentialToken($data['credentialId'], $data['publicId'], $data['secret'], $data['token'], $data['hash']);
	}
}
