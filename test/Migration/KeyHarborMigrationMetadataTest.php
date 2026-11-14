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

namespace Base3\KeyHarbor\Test\Migration;

use Base3\CoreFoundation\Migration\Api\IDatabaseMigration;
use Base3\KeyHarbor\Migration\Migration001CreateCredentialTables;
use Base3\KeyHarbor\Migration\Migration002CredentialSubjects;
use Base3\KeyHarbor\Migration\Migration003ResourceConstraints;
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
