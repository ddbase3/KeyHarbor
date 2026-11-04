<?php declare(strict_types=1);

namespace KeyHarbor\Test\Security;

use InvalidArgumentException;
use KeyHarbor\Security\CredentialTokenService;
use PHPUnit\Framework\TestCase;

final class CredentialTokenServiceEdgeCasesTest extends TestCase {

	public function testGeneratedTokenHasExpectedUnpaddedBase64UrlComponents(): void {
		$generated = (new CredentialTokenService())->generate();

		self::assertSame(1, preg_match('/^[a-f0-9]{32}$/', $generated->getCredentialId()));
		self::assertSame(1, preg_match('/^[a-f0-9]{20}$/', $generated->getPublicId()));
		self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/', $generated->getSecret()));
		self::assertSame('b3k_' . $generated->getPublicId() . '_' . $generated->getSecret(), $generated->getToken());
		self::assertSame(hash('sha256', $generated->getSecret()), $generated->getSecretHash());
	}

	public function testParsingAllowsSurroundingWhitespaceButNotEmbeddedWhitespace(): void {
		$service = new CredentialTokenService();
		$generated = $service->generate();

		$parsed = $service->parse(" \t" . $generated->getToken() . "\n");
		self::assertNotNull($parsed);
		self::assertSame($generated->getPublicId(), $parsed->getPublicId());
		self::assertNull($service->parse(substr($generated->getToken(), 0, 15) . ' ' . substr($generated->getToken(), 15)));
	}

	public function testTokenParsingRejectsWrongAlphabetLengthAndCase(): void {
		$service = new CredentialTokenService();
		$generated = $service->generate();
		$publicId = $generated->getPublicId();
		$secret = $generated->getSecret();
		foreach ([
			'b3k_A' . substr($publicId, 1) . '_' . $secret,
			'b3k_' . substr($publicId, 1) . '_' . $secret,
			'b3k_' . $publicId . '_' . substr($secret, 1),
			'b3k_' . $publicId . '_' . $secret . 'x',
			'b3k_' . $publicId . '_' . substr($secret, 0, 42) . '+',
			'b3k_' . $publicId . '_' . substr($secret, 0, 42) . '='
		] as $token) {
			self::assertNull($service->parse($token), $token);
		}
	}

	public function testSecretHashValidationIsCaseSensitive(): void {
		$service = new CredentialTokenService();
		$secret = 'known-secret';
		$hash = $service->hashSecret($secret);

		self::assertTrue($service->verifySecret($secret, $hash));
		self::assertFalse($service->verifySecret($secret, strtoupper($hash)));
		self::assertFalse($service->verifySecret($secret, substr($hash, 1)));
		self::assertFalse($service->verifySecret($secret, $hash . '0'));
		self::assertFalse($service->verifySecret($secret . 'x', $hash));
	}

	public function testGeneratedTokensCannotVerifyAgainstEachOthersHashes(): void {
		$service = new CredentialTokenService();
		$first = $service->generateForCredential(str_repeat('a', 32));
		$second = $service->generateForCredential(str_repeat('a', 32));

		self::assertFalse($service->verifySecret($first->getSecret(), $second->getSecretHash()));
		self::assertFalse($service->verifySecret($second->getSecret(), $first->getSecretHash()));
		self::assertSame($first->getCredentialId(), $second->getCredentialId());
	}

	public function testGenerateForCredentialRejectsInvalidIdentifierValues(): void {
		$service = new CredentialTokenService();
		foreach (['', 'abc', str_repeat('A', 32), str_repeat('a', 31), str_repeat('a', 33), ' ' . str_repeat('a', 32)] as $id) {
			try {
				$service->generateForCredential($id);
				self::fail('Expected invalid credential identifier to be rejected.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('32 lowercase hexadecimal', $exception->getMessage());
			}
		}
	}
}
