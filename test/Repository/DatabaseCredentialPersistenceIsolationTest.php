<?php declare(strict_types=1);

namespace KeyHarbor\Test\Repository;

use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Repository\DatabaseCredentialRepository;
use PHPUnit\Framework\TestCase;

final class DatabaseCredentialPersistenceIsolationTest extends TestCase {

	public function testEmptyLookupDoesNotLoadGrantsOrModifyStorage(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);

		self::assertNull($repository->getById(str_repeat('a', 32)));
		self::assertSame([], $database->multiQueries);
		self::assertSame([], $database->queries);
		self::assertCount(1, $database->singleQueries);
	}

	public function testIdLookupEscapesUnexpectedQuoteCharactersInIdentifiers(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);

		self::assertNull($repository->getById("actor'credential"));
		self::assertStringContainsString("WHERE id='actor\\'credential' LIMIT 1", $database->singleQueries[0]);
	}

	public function testCredentialInsertionEscapesOwnerAndLabelButRetainsNullSecretColumns(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);
		$credential = new ApiCredential(
			str_repeat('a', 32), str_repeat('b', 20), '42', "o'brien", "O'Brian", "test'o@example.test",
			'en', "Example's key", str_repeat('c', 64), false, null, null,
			1000, null, null, null, null, ['example:read']
		);
		$repository->insert($credential);

		self::assertCount(2, $database->queries);
		self::assertStringContainsString("'o\\'brien'", $database->queries[0]);
		self::assertStringContainsString("'O\\'Brian'", $database->queries[0]);
		self::assertStringContainsString("'Example\\'s key'", $database->queries[0]);
		self::assertStringContainsString("'test\\'o@example.test'", $database->queries[0]);
		self::assertStringContainsString('NULL, NULL, 1000', $database->queries[0]);
		self::assertStringContainsString('NULL, NULL)', $database->queries[1]);
	}

	public function testGrantRowsForOtherCredentialsNeverChangeLoadedCredentialAccess(): void {
		$id = str_repeat('a', 32);
		$database = new RepositoryTestDatabase();
		$database->singleResponses[] = $this->row($id, str_repeat('b', 20));
		$database->multiResponses[] = [
			['credential_id' => str_repeat('e', 32), 'service_id' => 'other:admin', 'operations_json' => null, 'constraints_json' => null],
			['credential_id' => $id, 'service_id' => 'example:read', 'operations_json' => '["read"]', 'constraints_json' => null]
		];
		$credential = (new DatabaseCredentialRepository($database))->getById($id);

		self::assertNotNull($credential);
		self::assertSame(['example:read'], $credential->getServiceIds());
		self::assertTrue($credential->hasOperation('example:read', 'read'));
		self::assertFalse($credential->hasService('other:admin'));
	}

	public function testBatchedGrantsNeverLeakBetweenTwoCredentials(): void {
		$firstId = str_repeat('a', 32);
		$secondId = str_repeat('d', 32);
		$database = new RepositoryTestDatabase();
		$database->multiResponses[] = [
			$this->row($firstId, str_repeat('b', 20)),
			$this->row($secondId, str_repeat('e', 20))
		];
		$firstConstraint = ['getEntry' => ['version' => 1, 'expression' => ['kind' => 'eq', 'attribute' => 'owner', 'value' => '42']]];
		$secondConstraint = ['deleteEntry' => ['version' => 1, 'expression' => ['kind' => 'eq', 'attribute' => 'owner', 'value' => '99']]];
		$database->multiResponses[] = [
			['credential_id' => $firstId, 'service_id' => 'example:read', 'operations_json' => '["getEntry"]', 'constraints_json' => json_encode($firstConstraint, JSON_THROW_ON_ERROR)],
			['credential_id' => $secondId, 'service_id' => 'example:write', 'operations_json' => '["deleteEntry"]', 'constraints_json' => json_encode($secondConstraint, JSON_THROW_ON_ERROR)]
		];
		$loaded = (new DatabaseCredentialRepository($database))->listAll();

		self::assertCount(2, $loaded);
		self::assertSame($firstConstraint, $loaded[0]->getResourceConstraints()['example:read']);
		self::assertSame($secondConstraint, $loaded[1]->getResourceConstraints()['example:write']);
		self::assertFalse($loaded[0]->hasService('example:write'));
		self::assertFalse($loaded[1]->hasService('example:read'));
		self::assertCount(2, $database->multiQueries);
	}

	public function testDeletingRevokedCredentialIsNotReportedSuccessfulWhileItStillExists(): void {
		$id = str_repeat('a', 32);
		$database = new RepositoryTestDatabase();
		$row = $this->row($id, str_repeat('b', 20));
		$row['revoked_at'] = 1500;
		$database->singleResponses = [$row, $row];
		$grant = ['credential_id' => $id, 'service_id' => 'example:read', 'operations_json' => null, 'constraints_json' => null];
		$database->multiResponses = [[$grant], [$grant]];

		self::assertFalse((new DatabaseCredentialRepository($database))->deleteRevoked($id));
		self::assertCount(2, $database->queries);
		self::assertStringContainsString('DELETE FROM base3_keyharbor_grant', $database->queries[0]);
		self::assertStringContainsString('AND revoked_at IS NOT NULL', $database->queries[1]);
		self::assertCount(2, $database->singleQueries);
	}

	public function testExpiredBatchLoadsGrantsOnlyForReturnedCredentials(): void {
		$id = str_repeat('a', 32);
		$database = new RepositoryTestDatabase();
		$row = $this->row($id, str_repeat('b', 20));
		$row['expires_at'] = 1000;
		$database->multiResponses[] = [$row];
		$database->multiResponses[] = [[
			'credential_id' => $id, 'service_id' => 'example:read',
			'operations_json' => '["getEntry"]', 'constraints_json' => null
		]];

		$credentials = (new DatabaseCredentialRepository($database))->findExpired(1200);
		self::assertCount(1, $credentials);
		self::assertSame($id, $credentials[0]->getId());
		self::assertSame(1000, $credentials[0]->getExpiresAt());
		self::assertSame(['getEntry'], $credentials[0]->getOperationGrants()['example:read']);
		self::assertCount(2, $database->multiQueries);
	}

	/** @return array<string,mixed> */
	private function row(string $id, string $publicId): array {
		return [
			'id' => $id, 'public_id' => $publicId, 'owner_user_id' => '42',
			'owner_userid' => 'demo', 'owner_name' => 'Demo',
			'notification_address' => 'notify@example.test', 'notification_language' => 'en',
			'label' => 'Example key', 'secret_hash' => str_repeat('c', 64),
			'hmac_enabled' => 0, 'secret_ciphertext' => null, 'secret_cipher_nonce' => null,
			'created_at' => 900, 'expires_at' => null, 'revoked_at' => null,
			'warning_notified_at' => null, 'expiry_notified_at' => null,
			'subject_type' => 'user', 'subject_id' => '42'
		];
	}
}

require_once __DIR__ . '/RepositoryTestDatabase.php';
