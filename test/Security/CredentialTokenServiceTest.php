<?php declare(strict_types=1);

namespace KeyHarbor\Test\Security;

use KeyHarbor\Security\CredentialTokenService;
use PHPUnit\Framework\TestCase;

final class CredentialTokenServiceTest extends TestCase {

	public function testGeneratesParsableAndVerifiableToken(): void {
		$service = new CredentialTokenService();
		$generated = $service->generate();
		$parsed = $service->parse($generated->getToken());

		self::assertNotNull($parsed);
		self::assertSame($generated->getPublicId(), $parsed->getPublicId());
		self::assertSame($generated->getSecret(), $parsed->getSecret());
		self::assertTrue($service->verifySecret($parsed->getSecret(), $generated->getSecretHash()));
		self::assertFalse($service->verifySecret($parsed->getSecret() . 'x', $generated->getSecretHash()));
	}

	public function testRotatesTokenForExistingCredentialId(): void {
		$service = new CredentialTokenService();
		$credentialId = str_repeat('a', 32);
		$first = $service->generateForCredential($credentialId);
		$second = $service->generateForCredential($credentialId);

		self::assertSame($credentialId, $first->getCredentialId());
		self::assertSame($credentialId, $second->getCredentialId());
		self::assertNotSame($first->getPublicId(), $second->getPublicId());
		self::assertNotSame($first->getSecretHash(), $second->getSecretHash());
	}

	public function testRejectsMalformedTokens(): void {
		$service = new CredentialTokenService();

		self::assertNull($service->parse(''));
		self::assertNull($service->parse('b3k_wrong'));
		self::assertNull($service->parse('Bearer b3k_00000000000000000000_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
	}

	public function testGeneratesUniqueCredentials(): void {
		$service = new CredentialTokenService();
		$first = $service->generate();
		$second = $service->generate();

		self::assertNotSame($first->getCredentialId(), $second->getCredentialId());
		self::assertNotSame($first->getPublicId(), $second->getPublicId());
		self::assertNotSame($first->getToken(), $second->getToken());
	}
}
