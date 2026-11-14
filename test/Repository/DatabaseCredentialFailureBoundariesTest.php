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

namespace Base3\KeyHarbor\Test\Repository;

use InvalidArgumentException;
use JsonException;
use Base3\KeyHarbor\Model\ApiCredential;
use Base3\KeyHarbor\Repository\DatabaseCredentialRepository;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class DatabaseCredentialFailureBoundariesTest extends TestCase {

	public function testMissingCredentialCannotBeUpdatedWithoutAnyWrite(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);
		self::assertFalse($repository->update($this->credential()));
		self::assertSame([], $database->queries);
		self::assertCount(1, $database->singleQueries);
	}

	public function testMissingOwnedCredentialCannotBeUpdatedWithoutAnyWrite(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);
		self::assertFalse($repository->updateForOwner($this->credential(), 'other-owner'));
		self::assertSame([], $database->queries);
		self::assertStringContainsString("owner_user_id='other-owner'", $database->singleQueries[0]);
	}

	public function testMissingCredentialCannotBeDeletedOrReportedDeleted(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);
		self::assertFalse($repository->deleteRevoked(str_repeat('a', 32)));
		self::assertFalse($repository->deleteRevokedForOwner(str_repeat('a', 32), '42'));
		self::assertSame([], $database->queries);
	}

	public function testStoredEmptyGrantsNeverRestoreAnUnrestrictedCredential(): void {
		$database = new RepositoryTestDatabase();
		$database->singleResponses[] = $this->row();
		$database->multiResponses[] = [];
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Credentials must grant at least one service.');
		(new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));
	}

	public function testStoredInvalidOperationsJsonThrowsWithoutHydratingAnUnrestrictedGrant(): void {
		$database = $this->databaseWithGrant('{not-json', null);
		$this->expectException(JsonException::class);
		(new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));
	}

	public function testStoredScalarOperationGrantsAreRejected(): void {
		$database = $this->databaseWithGrant('"read"', null);
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Stored operation grants must be JSON lists.');
		(new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));
	}

	public function testStoredScalarResourceConstraintsAreRejected(): void {
		$database = $this->databaseWithGrant('["read"]', '"unrestricted"');
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Stored resource constraints must be keyed objects.');
		(new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));
	}

	public function testStoredInvalidConstraintJsonThrowsWithoutSilentlyDroppingIt(): void {
		$database = $this->databaseWithGrant('["read"]', '{not-json');
		$this->expectException(JsonException::class);
		(new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));
	}

	public function testNotificationMarkersRejectNonPositiveTimestampsBeforeDatabaseIO(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);
		foreach ([0, -1] as $timestamp) {
			foreach (['markWarningNotified', 'markExpiryNotified'] as $method) {
				try {
					$repository->$method(str_repeat('a', 32), $timestamp);
					self::fail('Expected invalid notification timestamp to be rejected.');
				} catch (InvalidArgumentException $exception) {
					self::assertSame('Credential query timestamps must be positive.', $exception->getMessage());
				}
			}
		}
		self::assertSame([], $database->queries);
		self::assertSame([], $database->scalarQueries);
	}

	public function testNotificationMarkerMustMatchPersistedIntegerValue(): void {
		foreach (['invalid', '2001', null, false] as $storedValue) {
			$database = new RepositoryTestDatabase();
			$database->scalarResponses[] = $storedValue;
			self::assertFalse((new DatabaseCredentialRepository($database))->markExpiryNotified(str_repeat('a', 32), 2000));
		}
	}

	public function testPersistedNullableFieldsAreConvertedWithoutCrossingUserIdentity(): void {
		$database = $this->databaseWithGrant(null, null);
		$database->singleResponses[0]['expires_at'] = '2000';
		$database->singleResponses[0]['warning_notified_at'] = '1800';
		$database->singleResponses[0]['subject_type'] = 'user';
		$database->singleResponses[0]['subject_id'] = '';
		$credential = (new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));
		self::assertNotNull($credential);
		self::assertSame('42', $credential->getSubject()->getId());
		self::assertSame(2000, $credential->getExpiresAt());
		self::assertSame(1800, $credential->getWarningNotifiedAt());
		self::assertNull($credential->getSecretCiphertext());
		self::assertNull($credential->getExpiryNotifiedAt());
	}

	private function databaseWithGrant(?string $operationsJson, ?string $constraintsJson): RepositoryTestDatabase {
		$database = new RepositoryTestDatabase();
		$database->singleResponses[] = $this->row();
		$database->multiResponses[] = [[
			'credential_id' => str_repeat('a', 32),
			'service_id' => 'example:read',
			'operations_json' => $operationsJson,
			'constraints_json' => $constraintsJson
		]];
		return $database;
	}

	/** @return array<string,mixed> */
	private function row(): array {
		return [
			'id' => str_repeat('a', 32), 'public_id' => str_repeat('b', 20), 'owner_user_id' => '42',
			'owner_userid' => 'demo', 'owner_name' => 'Demo User', 'notification_address' => '',
			'notification_language' => 'en', 'label' => 'Demo key', 'secret_hash' => str_repeat('c', 64),
			'hmac_enabled' => 0, 'secret_ciphertext' => null, 'secret_cipher_nonce' => null,
			'created_at' => 1000, 'expires_at' => null, 'revoked_at' => null,
			'warning_notified_at' => null, 'expiry_notified_at' => null
		];
	}

	private function credential(): ApiCredential {
		return new ApiCredential(
			str_repeat('a', 32), str_repeat('b', 20), '42', 'demo', 'Demo User', '', 'en',
			'Demo key', str_repeat('c', 64), false, null, null, 1000, null, null, null, null, ['example:read']
		);
	}
}

require_once __DIR__ . '/RepositoryTestDatabase.php';
