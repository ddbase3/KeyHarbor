<?php declare(strict_types=1);

namespace KeyHarbor\Test\Dto;

use InvalidArgumentException;
use KeyHarbor\Dto\GeneratedCredentialToken;
use KeyHarbor\Dto\IssuedCredential;
use KeyHarbor\Model\ApiCredential;
use PHPUnit\Framework\TestCase;

final class IssuedCredentialTest extends TestCase {

	public function testKeepsMatchingCredentialAndOneTimeTokenObjects(): void {
		$token = $this->token();
		$credential = $this->credential();
		$issued = new IssuedCredential($credential, $token);

		self::assertSame($credential, $issued->getCredential());
		self::assertSame($token, $issued->getGeneratedToken());
		self::assertSame($credential->getSecretHash(), $issued->getGeneratedToken()->getSecretHash());
	}

	public function testRejectsMismatchedCredentialId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Issued credential and token ids must match.');
		new IssuedCredential($this->credential(), $this->token(id: str_repeat('d', 32)));
	}

	public function testRejectsMismatchedPublicId(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Issued credential and token public ids must match.');
		new IssuedCredential($this->credential(), $this->token(publicId: str_repeat('e', 20)));
	}

	public function testRejectsMismatchedSecretHash(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Issued credential and token hashes must match.');
		new IssuedCredential($this->credential(), $this->token(hash: str_repeat('f', 64)));
	}

	public function testRejectsTokenFromPreviousRotation(): void {
		$credential = $this->credential()->withRotatedSecret(str_repeat('e', 20), str_repeat('f', 64));

		$this->expectException(InvalidArgumentException::class);
		new IssuedCredential($credential, $this->token());
	}

	private function token(
		string $id = '',
		string $publicId = '',
		string $hash = ''
	): GeneratedCredentialToken {
		$id = $id === '' ? str_repeat('a', 32) : $id;
		$publicId = $publicId === '' ? str_repeat('b', 20) : $publicId;
		$hash = $hash === '' ? str_repeat('c', 64) : $hash;
		$secret = str_repeat('Z', 43);
		return new GeneratedCredentialToken($id, $publicId, $secret, 'b3k_' . $publicId . '_' . $secret, $hash);
	}

	private function credential(): ApiCredential {
		return new ApiCredential(
			str_repeat('a', 32), str_repeat('b', 20), '42', 'admin', 'Administrator', 'admin@example.test', 'en',
			'Automation key', str_repeat('c', 64), false, null, null, 1000, null, null, null, null, ['example:read']
		);
	}
}
