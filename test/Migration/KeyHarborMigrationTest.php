<?php declare(strict_types=1);

namespace KeyHarbor\Test\Migration;

use Base3\Database\Api\IDatabase;
use KeyHarbor\Migration\Migration001CreateCredentialTables;
use KeyHarbor\Migration\Migration002CredentialSubjects;
use PHPUnit\Framework\TestCase;

final class KeyHarborMigrationTest extends TestCase {

	public function testSecondMigrationAddsSubjectsAndExactOperationsWithoutEditingFirst(): void {
		$database = new MigrationTestDatabase();
		(new Migration002CredentialSubjects($database))->up();
		self::assertCount(3, $database->queries);
		self::assertStringContainsString('ADD COLUMN subject_type', $database->queries[0]);
		self::assertStringContainsString('SET subject_id=owner_user_id', $database->queries[1]);
		self::assertStringContainsString('ADD COLUMN operations_json', $database->queries[2]);
	}

	public function testCreatesCredentialAndGrantTables(): void {
		$database = new MigrationTestDatabase();
		$migration = new Migration001CreateCredentialTables($database);

		$migration->up();

		self::assertTrue($database->connected());
		self::assertCount(2, $database->queries);
		self::assertStringContainsString('CREATE TABLE base3_keyharbor_key', $database->queries[0]);
		self::assertStringContainsString('CREATE TABLE base3_keyharbor_grant', $database->queries[1]);
	}
}

require_once __DIR__ . '/MigrationTestDatabase.php';
