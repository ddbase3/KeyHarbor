<?php declare(strict_types=1);

namespace KeyHarbor\Test\Repository;

use KeyHarbor\Repository\DatabaseCredentialRepository;
use PHPUnit\Framework\TestCase;

final class DatabaseCredentialQueryBoundariesTest extends TestCase {

	public function testExpiringWindowUsesExclusiveStartInclusiveEndAndMissingWarningMarker(): void {
		$database = new RepositoryTestDatabase();
		$credentials = (new DatabaseCredentialRepository($database))->findExpiring(1700, 1900);
		self::assertSame([], $credentials);
		self::assertTrue($database->connected());
		self::assertCount(1, $database->multiQueries);
		$query = $database->multiQueries[0];
		self::assertStringContainsString('expires_at > 1700', $query);
		self::assertStringContainsString('expires_at <= 1900', $query);
		self::assertStringContainsString('revoked_at IS NULL', $query);
		self::assertStringContainsString('warning_notified_at IS NULL', $query);
		self::assertStringContainsString('ORDER BY expires_at ASC, id ASC', $query);
		self::assertStringNotContainsString('expiry_notified_at IS NULL', $query);
	}

	public function testExpiredWindowIncludesBoundaryButExcludesRevokedAndAlreadyNotified(): void {
		$database = new RepositoryTestDatabase();
		self::assertSame([], (new DatabaseCredentialRepository($database))->findExpired(1900));
		self::assertCount(1, $database->multiQueries);
		$query = $database->multiQueries[0];
		self::assertStringContainsString('expires_at <= 1900', $query);
		self::assertStringContainsString('revoked_at IS NULL', $query);
		self::assertStringContainsString('expiry_notified_at IS NULL', $query);
		self::assertStringNotContainsString('warning_notified_at IS NULL', $query);
		self::assertStringContainsString('ORDER BY expires_at ASC, id ASC', $query);
	}

	public function testExpiringWindowRejectsInvalidBoundsWithoutQueryingDatabase(): void {
		foreach ([[0, 100], [-1, 100], [100, 0], [100, -5]] as [$start, $end]) {
			$database = new RepositoryTestDatabase();
			try {
				(new DatabaseCredentialRepository($database))->findExpiring($start, $end);
				self::fail('Non-positive expiry window bounds must be rejected.');
			} catch (\InvalidArgumentException $exception) {
				self::assertSame('Credential query timestamps must be positive.', $exception->getMessage());
			}
			self::assertSame([], $database->multiQueries);
			self::assertFalse($database->connected());
		}
	}

	public function testOwnerListQueryOnlyIncludesUserSubjectsAndStableSort(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);
		self::assertSame([], $repository->listByOwner('42'));
		$query = $database->multiQueries[0];
		self::assertStringContainsString("subject_type='user'", $query);
		self::assertStringContainsString("owner_user_id='42'", $query);
		self::assertStringContainsString('ORDER BY created_at DESC, id ASC', $query);
	}

	public function testOwnerListQueryUsesDatabaseEscapingForOwnerIdentifiers(): void {
		$database = new RepositoryTestDatabase();
		(new DatabaseCredentialRepository($database))->listByOwner("actor'42");
		self::assertStringContainsString("owner_user_id='actor\\'42'", $database->multiQueries[0]);
	}

	public function testAllCredentialQueryDoesNotImplicitlyRemoveTechnicalSubjects(): void {
		$database = new RepositoryTestDatabase();
		self::assertSame([], (new DatabaseCredentialRepository($database))->listAll());
		self::assertSame(
			'SELECT * FROM base3_keyharbor_key ORDER BY created_at DESC, id ASC',
			$database->multiQueries[0]
		);
	}

	public function testMissingCredentialsAreNotRehydratedOrMutated(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);
		$id = str_repeat('a', 32);
		self::assertNull($repository->getById($id));
		self::assertNull($repository->getByPublicId(str_repeat('b', 20)));
		self::assertFalse($repository->deleteRevoked($id));
		self::assertFalse($repository->deleteRevokedForOwner($id, '42'));
		self::assertSame([], $database->queries);
		self::assertSame([], $database->multiQueries);
		self::assertCount(4, $database->singleQueries);
	}

	public function testNotificationMarkerRequiresTheExactPersistedTimestamp(): void {
		$database = new RepositoryTestDatabase();
		$database->scalarResponses = [null, 1234, 'not-a-time'];
		$repository = new DatabaseCredentialRepository($database);
		self::assertFalse($repository->markWarningNotified(str_repeat('a', 32), 3000));
		self::assertFalse($repository->markExpiryNotified(str_repeat('a', 32), 3000));
		self::assertFalse($repository->markWarningNotified(str_repeat('a', 32), 3000));
		self::assertCount(3, $database->queries);
		self::assertCount(3, $database->scalarQueries);
		self::assertSame(0, $database->affectedRowsCount);
	}

	public function testListByOwnerLoadsBothCredentialsAndTheirGrantsInOneBatch(): void {
		$id1 = str_repeat('a', 32);
		$id2 = str_repeat('d', 32);
		$database = new RepositoryTestDatabase();
		$database->multiResponses[] = [
			$this->row($id1, str_repeat('b', 20), '42', 'user', '42'),
			$this->row($id2, str_repeat('e', 20), '42', 'user', '42')
		];
		$database->multiResponses[] = [
			['credential_id' => $id1, 'service_id' => 'example:read', 'operations_json' => '["getEntry"]', 'constraints_json' => null],
			['credential_id' => $id2, 'service_id' => 'example:write', 'operations_json' => '["deleteEntry"]', 'constraints_json' => null]
		];
		$credentials = (new DatabaseCredentialRepository($database))->listByOwner('42');
		self::assertCount(2, $credentials);
		self::assertSame($id1, $credentials[0]->getId());
		self::assertSame(['example:read'], $credentials[0]->getServiceIds());
		self::assertSame(['getEntry'], $credentials[0]->getOperationGrants()['example:read']);
		self::assertSame($id2, $credentials[1]->getId());
		self::assertSame(['example:write'], $credentials[1]->getServiceIds());
		self::assertCount(2, $database->multiQueries);
		self::assertStringContainsString("credential_id IN ('$id1', '$id2')", $database->multiQueries[1]);
	}

	public function testListAllPreservesTechnicalCredentialSubjectWithoutOwnerImpersonation(): void {
		$id = str_repeat('a', 32);
		$database = new RepositoryTestDatabase();
		$row = $this->row($id, str_repeat('b', 20), '42', 'service', 'contourz');
		$row['hmac_enabled'] = 1;
		$row['secret_ciphertext'] = 'encrypted';
		$row['secret_cipher_nonce'] = 'nonce';
		$database->multiResponses[] = [$row];
		$database->multiResponses[] = [[
			'credential_id' => $id, 'service_id' => 'example:read',
			'operations_json' => '["getEntries"]', 'constraints_json' => null
		]];
		$credentials = (new DatabaseCredentialRepository($database))->listAll();
		self::assertCount(1, $credentials);
		self::assertSame('service', $credentials[0]->getSubject()->getType());
		self::assertSame('contourz', $credentials[0]->getSubject()->getId());
		self::assertSame('42', $credentials[0]->getOwnerUserId());
		self::assertSame(['example:read'], $credentials[0]->getServiceIds());
		self::assertTrue($credentials[0]->isHmacEnabled());
	}

	/** @return array<string,mixed> */
	private function row(string $id, string $publicId, string $ownerId, string $type, string $subjectId): array {
		return [
			'id' => $id, 'public_id' => $publicId, 'owner_user_id' => $ownerId,
			'owner_userid' => 'demo', 'owner_name' => 'Demo User',
			'notification_address' => 'demo@example.test', 'notification_language' => 'en',
			'label' => 'Credential', 'secret_hash' => str_repeat('c', 64),
			'hmac_enabled' => 0, 'secret_ciphertext' => null, 'secret_cipher_nonce' => null,
			'created_at' => 1000, 'expires_at' => 1500, 'revoked_at' => null,
			'warning_notified_at' => null, 'expiry_notified_at' => null,
			'subject_type' => $type, 'subject_id' => $subjectId
		];
	}
}

require_once __DIR__ . '/RepositoryTestDatabase.php';
