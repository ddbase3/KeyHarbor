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

final class ApiCredentialValidationBoundariesTest extends TestCase {

	public function testAllPublicMetadataGettersPreserveTheirOriginalValues(): void {
		$credential = $this->credential([
			'ownerLogin' => 'operator', 'ownerName' => 'Operator Name',
			'notificationAddress' => 'notify@example.invalid', 'notificationLanguage' => 'de',
			'expiresAt' => 2000, 'revokedAt' => 2200, 'warningNotifiedAt' => 1500, 'expiryNotifiedAt' => 2100
		]);
		self::assertSame(str_repeat('a', 32), $credential->getId());
		self::assertSame(str_repeat('b', 20), $credential->getPublicId());
		self::assertSame('42', $credential->getOwnerUserId());
		self::assertSame('operator', $credential->getOwnerLogin());
		self::assertSame('Operator Name', $credential->getOwnerName());
		self::assertSame('notify@example.invalid', $credential->getNotificationAddress());
		self::assertSame('de', $credential->getNotificationLanguage());
		self::assertSame('An API key', $credential->getLabel());
		self::assertSame(str_repeat('c', 64), $credential->getSecretHash());
		self::assertSame(1000, $credential->getCreatedAt());
		self::assertSame(2000, $credential->getExpiresAt());
		self::assertSame(2200, $credential->getRevokedAt());
		self::assertSame(1500, $credential->getWarningNotifiedAt());
		self::assertSame(2100, $credential->getExpiryNotifiedAt());
		self::assertSame('42', $credential->getSubject()->getId());
		self::assertTrue($credential->isRevoked());
		self::assertFalse($credential->isHmacEnabled());
		self::assertNull($credential->getSecretCiphertext());
		self::assertNull($credential->getSecretCipherNonce());
	}

	public function testMissingExpirationNeverBecomesExpired(): void {
		$credential = $this->credential();
		self::assertFalse($credential->isExpired(PHP_INT_MAX));
		self::assertFalse($credential->isRevoked());
	}

	public function testExpirationBoundaryIsInclusive(): void {
		$credential = $this->credential(['expiresAt' => 2000]);
		self::assertFalse($credential->isExpired(1999));
		self::assertTrue($credential->isExpired(2000));
		self::assertTrue($credential->isExpired(2001));
	}

	public function testMalformedIdentifiersAndSecretHashesAreRejected(): void {
		foreach ([
			['id' => str_repeat('A', 32)],
			['id' => str_repeat('a', 31)],
			['publicId' => str_repeat('B', 20)],
			['publicId' => str_repeat('b', 19)],
			['secretHash' => str_repeat('C', 64)],
			['secretHash' => str_repeat('c', 63)]
		] as $overrides) {
			$this->assertInvalid($overrides);
		}
	}

	public function testEmptyOwnerAndLabelAreRejected(): void {
		foreach ([['ownerUserId' => '  '], ['label' => ''], ['label' => "\t"]] as $overrides) {
			$this->assertInvalid($overrides);
		}
	}

	public function testCreationAndLifecycleTimestampsMustBePositive(): void {
		foreach (['createdAt', 'expiresAt', 'revokedAt', 'warningNotifiedAt', 'expiryNotifiedAt'] as $field) {
			$this->assertInvalid([$field => 0]);
			$this->assertInvalid([$field => -1]);
		}
	}

	public function testHmacCredentialsRequireBothEncryptedSecretComponents(): void {
		foreach ([
			['secretCiphertext' => null, 'secretCipherNonce' => 'nonce'],
			['secretCiphertext' => 'cipher', 'secretCipherNonce' => null],
			['secretCiphertext' => ' ', 'secretCipherNonce' => 'nonce'],
			['secretCiphertext' => 'cipher', 'secretCipherNonce' => ' ']
		] as $overrides) {
			$this->assertInvalid(array_replace(['hmacEnabled' => true], $overrides));
		}
	}

	public function testBearerCredentialsRejectEitherSecretComponentIndependently(): void {
		$this->assertInvalid(['secretCiphertext' => 'encrypted']);
		$this->assertInvalid(['secretCipherNonce' => 'nonce']);
	}

	public function testServiceIdsRejectInvalidCharactersAndNormalizeWhitespaceAndDuplicates(): void {
		foreach (['UPPER', 'space in id', 'semi;colon', '', '/', 'ü'] as $invalid) {
			$this->assertInvalid(['serviceIds' => [$invalid]]);
		}
		$credential = $this->credential(['serviceIds' => [' example:write ', 'example:read', 'example:write']]);
		self::assertSame(['example:read', 'example:write'], $credential->getServiceIds());
		self::assertTrue($credential->hasService('example:write'));
		self::assertFalse($credential->hasService('missing'));
	}

	public function testOperationGrantsRejectInvalidServiceAndMethodNames(): void {
		foreach ([
			['missing' => ['getEntries']],
			['example:read' => ['1badMethod']],
			['example:read' => ['bad-method']],
			['example:read' => [3]],
			['example:read' => ['getEntries', null]]
		] as $invalid) {
			$this->assertInvalid(['operationGrants' => $invalid]);
		}
	}

	public function testServiceCredentialCannotRunWithoutHmacOrExplicitMethods(): void {
		$subject = new CredentialSubject(CredentialSubject::TYPE_SERVICE, 'service-one');
		$this->assertInvalid(['subject' => $subject]);
		$this->assertInvalid(['subject' => $subject, 'hmacEnabled' => true, 'secretCiphertext' => 'cipher', 'secretCipherNonce' => 'nonce']);
		$this->assertInvalid([
			'subject' => $subject, 'hmacEnabled' => true, 'secretCiphertext' => 'cipher', 'secretCipherNonce' => 'nonce',
			'operationGrants' => ['example:read' => []]
		]);
	}

	public function testTechnicalCredentialsUseExactGrantedOperationList(): void {
		$credential = $this->credential([
			'hmacEnabled' => true, 'secretCiphertext' => 'cipher', 'secretCipherNonce' => 'nonce',
			'subject' => new CredentialSubject(CredentialSubject::TYPE_SERVICE, 'service-one'),
			'operationGrants' => ['example:read' => ['write', 'read', 'read']]
		]);
		self::assertTrue($credential->isServiceCredential());
		self::assertTrue($credential->hasOperation('example:read', 'read'));
		self::assertFalse($credential->hasOperation('example:read', 'delete'));
		self::assertSame(['read', 'write'], $credential->getOperationGrants()['example:read']);
		self::assertSame('cipher', $credential->getSecretCiphertext());
		self::assertSame('nonce', $credential->getSecretCipherNonce());
	}

	public function testManagementCopyWithExplicitEmptyConstraintsRemovesOldConstraints(): void {
		$expression = ['version' => 1, 'expression' => ['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq', 'value' => '42']];
		$credential = $this->credential([
			'operationGrants' => ['example:read' => ['getEntry']],
			'resourceConstraints' => ['example:read' => ['getEntry' => $expression]]
		]);
		$copy = $credential->withManagementData('Changed', '', 'de', null, ['example:read'], null, []);
		self::assertSame([], $copy->getResourceConstraints());
		self::assertSame(['example:read' => ['getEntry' => $expression]], $credential->getResourceConstraints());
	}

	public function testRotationPreservesHmacSecretButReplacesEncryptedMaterial(): void {
		$credential = $this->credential(['hmacEnabled' => true, 'secretCiphertext' => 'old-secret', 'secretCipherNonce' => 'old-nonce']);
		$rotated = $credential->withRotatedSecret(str_repeat('d', 20), str_repeat('e', 64), 'new-secret', 'new-nonce');
		self::assertSame('old-secret', $credential->getSecretCiphertext());
		self::assertSame('new-secret', $rotated->getSecretCiphertext());
		self::assertSame('new-nonce', $rotated->getSecretCipherNonce());
		self::assertSame($credential->getId(), $rotated->getId());
		self::assertSame($credential->getCreatedAt(), $rotated->getCreatedAt());
	}

	private function assertInvalid(array $overrides): void {
		try {
			$this->credential($overrides);
			self::fail('Invalid credential metadata must be rejected.');
		} catch (InvalidArgumentException $exception) {
			self::assertNotSame('', $exception->getMessage());
		}
	}

	private function credential(array $overrides = []): ApiCredential {
		$values = array_replace([
			'id' => str_repeat('a', 32),
			'publicId' => str_repeat('b', 20),
			'ownerUserId' => '42',
			'ownerLogin' => 'user',
			'ownerName' => 'User',
			'notificationAddress' => '',
			'notificationLanguage' => 'en',
			'label' => 'An API key',
			'secretHash' => str_repeat('c', 64),
			'hmacEnabled' => false,
			'secretCiphertext' => null,
			'secretCipherNonce' => null,
			'createdAt' => 1000,
			'expiresAt' => null,
			'revokedAt' => null,
			'warningNotifiedAt' => null,
			'expiryNotifiedAt' => null,
			'serviceIds' => ['example:read'],
			'subject' => null,
			'operationGrants' => [],
			'resourceConstraints' => []
		], $overrides);
		return new ApiCredential(...$values);
	}
}
