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

	public function testTechnicalCredentialKeepsSubjectAndExactOperationsThroughCopies(): void {
		$credential = new ApiCredential(
			str_repeat('a', 32), str_repeat('b', 20), '42', 'admin', 'Admin', 'admin@example.test', 'en',
			'Contourz', str_repeat('c', 64), true, 'encrypted', 'nonce', 1000, null, null, null, null,
			['memora:entries'], new \CredentialFoundation\Dto\CredentialSubject('service', 'contourz'),
			['memora:entries' => ['getEntries']]
		);
		self::assertSame('contourz', $credential->getSubject()->getId());
		self::assertSame('42', $credential->getOwnerUserId());
		self::assertFalse($credential->hasOperation('memora:entries', 'deleteEntry'));
		self::assertTrue($credential->hasOperation('memora:entries', 'getEntries'));
		foreach ([$credential->withRevokedAt(2000), $credential->withManagementData('Changed', 'admin@example.test', 'en', null, ['memora:entries']), $credential->withRotatedSecret(str_repeat('d', 20), str_repeat('e', 64), 'new-encrypted', 'new-nonce')] as $copy) {
			self::assertTrue($copy->isServiceCredential());
			self::assertSame('contourz', $copy->getSubject()->getId());
			self::assertFalse($copy->hasOperation('memora:entries', 'deleteEntry'));
		}
	}

	public function testRejectsServiceCredentialWithoutExactOperationGrants(): void {
		$this->expectException(InvalidArgumentException::class);
		new ApiCredential(
			str_repeat('a', 32), str_repeat('b', 20), '42', 'admin', 'Admin', '', 'en',
			'Contourz', str_repeat('c', 64), true, 'encrypted', 'nonce', 1000, null, null, null, null,
			['memora:entries'], new \CredentialFoundation\Dto\CredentialSubject('service', 'contourz'), []
		);
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
