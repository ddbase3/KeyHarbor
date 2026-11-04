<?php declare(strict_types=1);

namespace KeyHarbor\Test\Migration;

use Base3\Migration\Api\IDatabaseMigration;
use KeyHarbor\Migration\Migration001CreateCredentialTables;
use KeyHarbor\Migration\Migration002CredentialSubjects;
use KeyHarbor\Migration\Migration003ResourceConstraints;
use PHPUnit\Framework\TestCase;

final class KeyHarborMigrationMetadataTest extends TestCase {

	public function testInitialCredentialSchemaMigrationExposesItsExactIdentity(): void {
		$migration = new Migration001CreateCredentialTables(new MigrationTestDatabase());

		self::assertInstanceOf(IDatabaseMigration::class, $migration);
		self::assertSame('keyharbormigration001createcredentialtables', Migration001CreateCredentialTables::getName());
		self::assertSame('001', $migration->getVersion());
		self::assertSame('Create KeyHarbor credential and service grant tables', $migration->getDescription());
	}

	public function testCredentialSubjectMigrationExposesItsExactIdentity(): void {
		$migration = new Migration002CredentialSubjects(new MigrationTestDatabase());

		self::assertInstanceOf(IDatabaseMigration::class, $migration);
		self::assertSame('keyharbormigration002credentialsubjects', Migration002CredentialSubjects::getName());
		self::assertSame('002', $migration->getVersion());
		self::assertSame('Add credential subjects and restricted service operations', $migration->getDescription());
	}

	public function testMigrationVersionsAreUniqueAndSortedWithResourceConstraintsLast(): void {
		$database = new MigrationTestDatabase();
		$versions = [
			(new Migration001CreateCredentialTables($database))->getVersion(),
			(new Migration002CredentialSubjects($database))->getVersion(),
			(new Migration003ResourceConstraints($database))->getVersion()
		];

		self::assertSame(['001', '002', '003'], $versions);
		self::assertSame(3, count(array_unique($versions)));
	}
}

require_once __DIR__ . '/MigrationTestDatabase.php';
