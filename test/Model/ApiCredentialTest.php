<?php declare(strict_types=1);

namespace KeyHarbor\Test\Model;

use InvalidArgumentException;
use KeyHarbor\Model\ApiCredential;
use PHPUnit\Framework\TestCase;

final class ApiCredentialTest extends TestCase {

	public function testNormalizesServiceIdsAndLifecycleState(): void {
		$credential = $this->credential([
			'example:write',
			'example:read',
			'example:write'
		]);

		self::assertSame(['example:read', 'example:write'], $credential->getServiceIds());
		self::assertFalse($credential->isRevoked());
		self::assertFalse($credential->isExpired(1999));
		self::assertTrue($credential->isExpired(2000));
	}

	public function testCreatesImmutableManagementCopies(): void {
		$credential = $this->credential(['example:read']);
		$updated = $credential->withManagementData(
			'Updated',
			'notify@example.test',
			'de',
			3000,
			['example:write']
		);
		$rotated = $updated->withRotatedSecret(str_repeat('d', 20), str_repeat('e', 64));
		$revoked = $rotated->withRevokedAt(2500);

		self::assertSame('Demo key', $credential->getLabel());
		self::assertSame('Updated', $updated->getLabel());
		self::assertSame(['example:write'], $updated->getServiceIds());
		self::assertSame(str_repeat('d', 20), $rotated->getPublicId());
		self::assertSame(str_repeat('e', 64), $rotated->getSecretHash());
		self::assertSame(2500, $revoked->getRevokedAt());
	}

	public function testRejectsCredentialWithoutServiceGrant(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->credential([]);
	}

	public function testRejectsHmacWithoutEncryptedSecret(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->credential(['example:read'], true);
	}

	/**
	 * @param array<int,string> $serviceIds
	 */
	private function credential(array $serviceIds, bool $hmacEnabled = false): ApiCredential {
		return new ApiCredential(
			str_repeat('a', 32),
			str_repeat('b', 20),
			'42',
			'demo',
			'Demo User',
			'demo@example.test',
			'en',
			'Demo key',
			str_repeat('c', 64),
			$hmacEnabled,
			null,
			null,
			1000,
			2000,
			null,
			null,
			null,
			$serviceIds
		);
	}
}
