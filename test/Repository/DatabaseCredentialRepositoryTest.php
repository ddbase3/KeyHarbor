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

use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\KeyHarbor\Model\ApiCredential;
use Base3\KeyHarbor\Repository\DatabaseCredentialRepository;
use PHPUnit\Framework\TestCase;

final class DatabaseCredentialRepositoryTest extends TestCase {

	public function testInsertsCredentialAndServiceGrantsWithoutTransactions(): void {
		$database = new RepositoryTestDatabase();
		$repository = new DatabaseCredentialRepository($database);

		$repository->insert($this->credential());

		self::assertSame(0, $database->beginCount);
		self::assertSame(0, $database->commitCount);
		self::assertSame(0, $database->rollbackCount);
		self::assertStringContainsString('INSERT INTO base3_keyharbor_key', $database->queries[0]);
		self::assertStringContainsString("'example:read'", $database->queries[1]);
		self::assertStringContainsString("'example:write'", $database->queries[2]);
	}

	public function testStoresExplicitOperationGrantsForServiceCredential(): void {
		$database = new RepositoryTestDatabase();
		$repo = new DatabaseCredentialRepository($database);
		$credential = new ApiCredential(
			str_repeat('a', 32), str_repeat('b', 20), '42', 'admin', 'Admin', '', 'en',
			'Contourz', str_repeat('c', 64), true, 'encrypted', 'nonce', 1000, null, null, null, null,
			['example:read'], new \Base3\CredentialFoundation\Dto\CredentialSubject('service', 'contourz'),
			['example:read' => ['getEntries']]
		);
		$repo->insert($credential);
		self::assertStringContainsString("'service'", $database->queries[0]);
		self::assertStringContainsString("'contourz'", $database->queries[0]);
		self::assertStringContainsString('operations_json', $database->queries[1]);
		self::assertStringContainsString('getEntries', $database->queries[1]);
	}

	public function testOwnerLookupExcludesTechnicalCredentialInStorageQuery(): void {
		$database = new RepositoryTestDatabase();
		$repo = new DatabaseCredentialRepository($database);
		$repo->getByOwner(str_repeat('a', 32), '42');
		self::assertStringContainsString("subject_type='user'", $database->singleQueries[0]);
	}

	public function testLoadsCredentialByPublicIdWithGrants(): void {
		$database = new RepositoryTestDatabase();
		$database->singleResponses[] = $this->row();
		$database->multiResponses[] = [
			['credential_id' => str_repeat('a', 32), 'service_id' => 'example:read'],
			['credential_id' => str_repeat('a', 32), 'service_id' => 'example:write']
		];
		$repository = new DatabaseCredentialRepository($database);

		$credential = $repository->getByPublicId(str_repeat('b', 20));

		self::assertNotNull($credential);
		self::assertSame('42', $credential->getOwnerUserId());
		self::assertSame(['example:read', 'example:write'], $credential->getServiceIds());
		self::assertStringContainsString('WHERE public_id=', $database->singleQueries[0]);
	}

	public function testUpdatePersistsRotatedPublicId(): void {
		$database = new RepositoryTestDatabase();
		$database->singleResponses[] = $this->row();
		$database->multiResponses[] = [
			['credential_id' => str_repeat('a', 32), 'service_id' => 'example:read'],
			['credential_id' => str_repeat('a', 32), 'service_id' => 'example:write']
		];
		$repository = new DatabaseCredentialRepository($database);
		$rotated = $this->credential()->withRotatedSecret(str_repeat('d', 20), str_repeat('e', 64));

		self::assertTrue($repository->update($rotated));
		self::assertStringContainsString("public_id='" . str_repeat('d', 20) . "'", $database->queries[0]);
		self::assertStringContainsString("secret_hash='" . str_repeat('e', 64) . "'", $database->queries[0]);
		self::assertSame(0, $database->beginCount);
		self::assertSame(0, $database->commitCount);
		self::assertSame(0, $database->rollbackCount);
	}


	public function testDeletesGrantsBeforeRevokedCredentialWithoutAffectedRowsOrTransactions(): void {
		$database = new RepositoryTestDatabase();
		$database->affectedRowsResult = 0;
		$database->singleResponses[] = $this->row(true);
		$database->singleResponses[] = null;
		$database->multiResponses[] = [
			['credential_id' => str_repeat('a', 32), 'service_id' => 'example:read']
		];
		$repository = new DatabaseCredentialRepository($database);

		self::assertTrue($repository->deleteRevoked(str_repeat('a', 32)));
		self::assertStringContainsString('DELETE FROM base3_keyharbor_grant', $database->queries[0]);
		self::assertStringContainsString('DELETE FROM base3_keyharbor_key', $database->queries[1]);
		self::assertStringContainsString('revoked_at IS NOT NULL', $database->queries[1]);
		self::assertSame(0, $database->affectedRowsCount);
		self::assertSame(0, $database->beginCount);
		self::assertSame(0, $database->commitCount);
		self::assertSame(0, $database->rollbackCount);
	}

	public function testRejectsRepositoryDeletionOfActiveCredential(): void {
		$database = new RepositoryTestDatabase();
		$database->singleResponses[] = $this->row(false);
		$database->multiResponses[] = [
			['credential_id' => str_repeat('a', 32), 'service_id' => 'example:read']
		];
		$repository = new DatabaseCredentialRepository($database);

		self::assertFalse($repository->deleteRevoked(str_repeat('a', 32)));
		self::assertSame([], $database->queries);
	}

	public function testOwnerDeletionIncludesOwnerInCredentialDeleteCondition(): void {
		$database = new RepositoryTestDatabase();
		$database->singleResponses[] = $this->row(true);
		$database->singleResponses[] = null;
		$database->multiResponses[] = [
			['credential_id' => str_repeat('a', 32), 'service_id' => 'example:read']
		];
		$repository = new DatabaseCredentialRepository($database);

		self::assertTrue($repository->deleteRevokedForOwner(str_repeat('a', 32), '42'));
		self::assertStringContainsString("owner_user_id='42'", $database->singleQueries[0]);
		self::assertStringContainsString("owner_user_id='42'", $database->queries[1]);
	}

	public function testMarksWarningNotificationWithoutAffectedRows(): void {
		$database = new RepositoryTestDatabase();
		$database->affectedRowsResult = 0;
		$database->scalarResponses[] = 3000;
		$repository = new DatabaseCredentialRepository($database);

		self::assertTrue($repository->markWarningNotified(str_repeat('a', 32), 3000));
		self::assertStringContainsString('warning_notified_at=3000', $database->queries[0]);
		self::assertStringContainsString('warning_notified_at IS NULL', $database->queries[0]);
		self::assertStringContainsString('SELECT warning_notified_at', $database->scalarQueries[0]);
		self::assertSame(0, $database->affectedRowsCount);
	}

	public function testMarksExpiryNotificationWithoutAffectedRows(): void {
		$database = new RepositoryTestDatabase();
		$database->affectedRowsResult = 0;
		$database->scalarResponses[] = '4000';
		$repository = new DatabaseCredentialRepository($database);

		self::assertTrue($repository->markExpiryNotified(str_repeat('a', 32), 4000));
		self::assertStringContainsString('expiry_notified_at=4000', $database->queries[0]);
		self::assertStringContainsString('expiry_notified_at IS NULL', $database->queries[0]);
		self::assertStringContainsString('SELECT expiry_notified_at', $database->scalarQueries[0]);
		self::assertSame(0, $database->affectedRowsCount);
	}

	public function testRejectsUnpersistedNotificationMarker(): void {
		$database = new RepositoryTestDatabase();
		$database->scalarResponses[] = null;
		$repository = new DatabaseCredentialRepository($database);

		self::assertFalse($repository->markWarningNotified(str_repeat('a', 32), 3000));
	}


	public function testOwnerLookupIncludesOwnerInStorageCondition(): void {
		$database = new RepositoryTestDatabase();
		$database->singleResponses[] = null;
		$repository = new DatabaseCredentialRepository($database);

		self::assertNull($repository->getByOwner(str_repeat('a', 32), '42'));
		self::assertStringContainsString("owner_user_id='42'", $database->singleQueries[0]);
	}

	private function credential(): ApiCredential {
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
			false,
			null,
			null,
			1000,
			2000,
			null,
			null,
			null,
			['example:write', 'example:read']
		);
	}

	/** @return array<string,mixed> */
	private function row(bool $revoked = false): array {
		return [
			'id' => str_repeat('a', 32),
			'public_id' => str_repeat('b', 20),
			'owner_user_id' => '42',
			'owner_userid' => 'demo',
			'owner_name' => 'Demo User',
			'notification_address' => 'demo@example.test',
			'notification_language' => 'en',
			'label' => 'Demo key',
			'secret_hash' => str_repeat('c', 64),
			'hmac_enabled' => 0,
			'secret_ciphertext' => null,
			'secret_cipher_nonce' => null,
			'created_at' => 1000,
			'expires_at' => 2000,
			'revoked_at' => $revoked ? 1500 : null,
			'warning_notified_at' => null,
			'expiry_notified_at' => null
		];
	}
}

require_once __DIR__ . '/RepositoryTestDatabase.php';
