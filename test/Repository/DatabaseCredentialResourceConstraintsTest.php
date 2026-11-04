<?php declare(strict_types=1);

namespace KeyHarbor\Test\Repository;

use JsonException;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Repository\DatabaseCredentialRepository;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class DatabaseCredentialResourceConstraintsTest extends TestCase {

	public function testInsertPersistsConstraintJsonWithinOwningServiceGrant(): void {
		$database = new RepositoryTestDatabase();
		$constraint = $this->constraint();
		$credential = $this->credential(['example:read' => ['getEntry' => $constraint]]);
		(new DatabaseCredentialRepository($database))->insert($credential);

		self::assertCount(2, $database->queries);
		self::assertStringContainsString('constraints_json', $database->queries[1]);
		self::assertStringContainsString("'example:read'", $database->queries[1]);
		self::assertStringContainsString(addslashes(json_encode(['getEntry' => $constraint], JSON_THROW_ON_ERROR)), $database->queries[1]);
	}

	public function testInsertWithoutConstraintsPersistsNullNotAnImplicitGrant(): void {
		$database = new RepositoryTestDatabase();
		(new DatabaseCredentialRepository($database))->insert($this->credential());

		self::assertStringContainsString('constraints_json', $database->queries[1]);
		self::assertStringContainsString('NULL)', $database->queries[1]);
	}

	public function testLoadsConstraintsWithoutInterpretingThemAsAuthorization(): void {
		$database = $this->databaseWithGrant(json_encode(['getEntry' => $this->constraint()], JSON_THROW_ON_ERROR));
		$credential = (new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));

		self::assertNotNull($credential);
		self::assertSame($this->constraint(), $credential->getResourceConstraint('example:read', 'getEntry'));
		self::assertSame(['getEntry'], $credential->getOperationGrants()['example:read']);
		self::assertNull($credential->getResourceConstraint('example:read', 'deleteEntry'));
	}

	public function testRejectsStoredConstraintListsInsteadOfOperationMaps(): void {
		$database = $this->databaseWithGrant('["value"]');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Stored resource constraints must be keyed objects.');
		(new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));
	}

	public function testRejectsInvalidStoredConstraintJson(): void {
		$database = $this->databaseWithGrant('{invalid-json');

		$this->expectException(JsonException::class);
		(new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));
	}

	public function testRejectsStoredOperationsThatAreNotLists(): void {
		$database = $this->databaseWithGrant(null, '{"getEntry":true}');

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('Stored operation grants must be JSON lists.');
		(new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));
	}

	public function testLegacyGrantWithoutConstraintsContainsNoImplicitConstraintExpression(): void {
		$database = $this->databaseWithGrant(null);
		$credential = (new DatabaseCredentialRepository($database))->getById(str_repeat('a', 32));

		self::assertNotNull($credential);
		self::assertSame([], $credential->getResourceConstraints());
		self::assertNull($credential->getResourceConstraint('example:read', 'getEntry'));
	}

	public function testRejectsNonPositiveNotificationQueryTimestampsBeforeDatabaseAccess(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);
		foreach ([0, -1] as $timestamp) {
			try {
				$repository->findExpired($timestamp);
				self::fail('Expected invalid timestamp to be rejected.');
			} catch (\InvalidArgumentException $exception) {
				self::assertSame('Credential query timestamps must be positive.', $exception->getMessage());
			}
		}
		self::assertSame([], $database->queries);
	}

	private function databaseWithGrant(?string $constraintsJson, ?string $operationsJson = '["getEntry"]'): RepositoryTestDatabase {
		$database = new RepositoryTestDatabase();
		$database->singleResponses[] = [
			'id' => str_repeat('a', 32),
			'public_id' => str_repeat('b', 20),
			'owner_user_id' => '42',
			'owner_userid' => 'admin',
			'owner_name' => 'Administrator',
			'notification_address' => 'admin@example.test',
			'notification_language' => 'en',
			'label' => 'Automation key',
			'secret_hash' => str_repeat('c', 64),
			'hmac_enabled' => 0,
			'secret_ciphertext' => null,
			'secret_cipher_nonce' => null,
			'created_at' => 1000,
			'expires_at' => null,
			'revoked_at' => null,
			'warning_notified_at' => null,
			'expiry_notified_at' => null
		];
		$database->multiResponses[] = [[
			'credential_id' => str_repeat('a', 32),
			'service_id' => 'example:read',
			'operations_json' => $operationsJson,
			'constraints_json' => $constraintsJson
		]];
		return $database;
	}

	private function credential(array $constraints = []): ApiCredential {
		return new ApiCredential(
			str_repeat('a', 32), str_repeat('b', 20), '42', 'admin', 'Administrator', '', 'en',
			'Automation key', str_repeat('c', 64), false, null, null, 1000, null, null, null, null,
			['example:read'], null, ['example:read' => ['getEntry']], $constraints
		);
	}

	private function constraint(): array {
		return ['version' => 1, 'expression' => ['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq', 'value' => '42']];
	}
}

require_once __DIR__ . '/RepositoryTestDatabase.php';
