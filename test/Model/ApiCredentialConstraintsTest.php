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

namespace Base3\KeyHarbor\Test\Model;

use Base3\CredentialFoundation\Dto\CredentialSubject;
use InvalidArgumentException;
use Base3\KeyHarbor\Model\ApiCredential;
use PHPUnit\Framework\TestCase;

final class ApiCredentialConstraintsTest extends TestCase {

	public function testUserCredentialAllowsOperationsWithoutExplicitOperationList(): void {
		$credential = $this->credential();

		self::assertTrue($credential->hasOperation('example:read', 'getEntries'));
		self::assertTrue($credential->hasOperation('example:read', 'deleteEntry'));
		self::assertFalse($credential->hasOperation('missing:service', 'getEntries'));
		self::assertFalse($credential->isServiceCredential());
		self::assertSame('42', $credential->getSubject()->getId());
	}

	public function testExplicitUserOperationGrantsLimitAuthorizedOperations(): void {
		$credential = $this->credential(operations: ['example:read' => ['getEntry', 'getEntries', 'getEntry']]);

		self::assertSame(['getEntries', 'getEntry'], $credential->getOperationGrants()['example:read']);
		self::assertTrue($credential->hasOperation('example:read', 'getEntries'));
		self::assertFalse($credential->hasOperation('example:read', 'deleteEntry'));
	}

	public function testTechnicalCredentialRequiresOnlyExplicitGrantedOperations(): void {
		$credential = $this->credential(
			technical: true,
			operations: ['example:read' => ['getEntry']]
		);

		self::assertTrue($credential->isServiceCredential());
		self::assertSame('service-one', $credential->getSubject()->getId());
		self::assertTrue($credential->hasOperation('example:read', 'getEntry'));
		self::assertFalse($credential->hasOperation('example:read', 'getEntries'));
	}

	public function testResourceConstraintsStayScopedToGrantedServiceAndOperation(): void {
		$constraint = ['version' => 1, 'expression' => ['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq', 'value' => '42']];
		$credential = $this->credential(
			operations: ['example:read' => ['getEntry']],
			constraints: ['example:read' => ['getEntry' => $constraint]]
		);

		self::assertSame(['example:read' => ['getEntry' => $constraint]], $credential->getResourceConstraints());
		self::assertSame($constraint, $credential->getResourceConstraint('example:read', 'getEntry'));
		self::assertNull($credential->getResourceConstraint('example:read', 'deleteEntry'));
		self::assertNull($credential->getResourceConstraint('unknown', 'getEntry'));
	}

	public function testManagementCopiesDropRemovedServicesAndTheirConstraints(): void {
		$constraint = ['version' => 1, 'expression' => ['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq', 'value' => 2]];
		$credential = $this->credential(
			services: ['example:read', 'example:write'],
			operations: ['example:read' => ['getEntry'], 'example:write' => ['deleteEntry']],
			constraints: ['example:read' => ['getEntry' => $constraint], 'example:write' => ['deleteEntry' => $constraint]]
		);
		$reduced = $credential->withManagementData('Reduced', 'admin@example.test', 'en', null, ['example:read']);

		self::assertSame(['example:read'], $reduced->getServiceIds());
		self::assertSame(['example:read' => ['getEntry']], $reduced->getOperationGrants());
		self::assertSame(['example:read' => ['getEntry' => $constraint]], $reduced->getResourceConstraints());
		self::assertSame(['example:read', 'example:write'], $credential->getServiceIds());
	}

	public function testRotationAndRevocationPreserveResourceConstraints(): void {
		$constraint = ['version' => 1, 'expression' => ['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq', 'value' => 42]];
		$credential = $this->credential(operations: ['example:read' => ['getEntry']], constraints: ['example:read' => ['getEntry' => $constraint]]);
		$rotated = $credential->withRotatedSecret(str_repeat('d', 20), str_repeat('e', 64));
		$revoked = $rotated->withRevokedAt(2500);

		self::assertSame($credential->getResourceConstraints(), $rotated->getResourceConstraints());
		self::assertSame($credential->getResourceConstraints(), $revoked->getResourceConstraints());
		self::assertSame(2500, $revoked->getRevokedAt());
		self::assertNull($credential->getRevokedAt());
	}

	public function testExpiryChangeClearsPreviousNotificationMarkers(): void {
		$credential = $this->credential(warningNotifiedAt: 1100, expiryNotifiedAt: 1200);
		$updated = $credential->withManagementData('Changed', 'notify@example.test', 'de', 3000, ['example:read']);

		self::assertNull($updated->getWarningNotifiedAt());
		self::assertNull($updated->getExpiryNotifiedAt());
		self::assertSame(1100, $credential->getWarningNotifiedAt());
		self::assertSame(1200, $credential->getExpiryNotifiedAt());
	}

	public function testUnchangedExpiryPreservesNotificationMarkers(): void {
		$credential = $this->credential(expiresAt: 2000, warningNotifiedAt: 1100, expiryNotifiedAt: 1200);
		$updated = $credential->withManagementData('Changed', 'notify@example.test', 'de', 2000, ['example:read']);

		self::assertSame(1100, $updated->getWarningNotifiedAt());
		self::assertSame(1200, $updated->getExpiryNotifiedAt());
	}

	public function testRejectsConstraintsForMissingServiceOrOperation(): void {
		foreach ([
			['unknown' => ['getEntry' => []]],
			['example:read' => ['deleteEntry' => []]],
			['example:read' => ['getEntry' => 'raw']]
		] as $invalid) {
			try {
				$this->credential(operations: ['example:read' => ['getEntry']], constraints: $invalid);
				self::fail('Resource constraints must belong to granted operations.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('Resource constraints require a granted', $exception->getMessage());
			}
		}
	}

	public function testRejectsOperationGrantsForUnselectedServices(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->credential(operations: ['unknown' => ['getEntry']]);
	}

	public function testRejectsEmptyTechnicalOperationGrants(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->credential(technical: true, operations: ['example:read' => []]);
	}

	public function testRejectsUserSubjectMismatch(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->credential(subject: new CredentialSubject(CredentialSubject::TYPE_USER, 'other-user'));
	}

	public function testRejectsBearerOnlyCredentialWithEncryptedMaterial(): void {
		$this->expectException(InvalidArgumentException::class);
		new ApiCredential(
			str_repeat('a', 32), str_repeat('b', 20), '42', 'admin', 'Administrator', '', 'en',
			'Automation key', str_repeat('c', 64), false, 'ciphertext', 'nonce', 1000, null, null, null, null, ['example:read']
		);
	}

	private function credential(
		array $services = ['example:read'],
		bool $technical = false,
		array $operations = [],
		array $constraints = [],
		?CredentialSubject $subject = null,
		?int $expiresAt = null,
		?int $warningNotifiedAt = null,
		?int $expiryNotifiedAt = null
	): ApiCredential {
		return new ApiCredential(
			str_repeat('a', 32), str_repeat('b', 20), '42', 'admin', 'Administrator', 'admin@example.test', 'en',
			'Automation key', str_repeat('c', 64), $technical, $technical ? 'encrypted' : null, $technical ? 'nonce' : null,
			1000, $expiresAt, null, $warningNotifiedAt, $expiryNotifiedAt,
			$services,
			$subject ?? ($technical ? new CredentialSubject('service', 'service-one') : null),
			$operations,
			$constraints
		);
	}
}
