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

use Base3\KeyHarbor\Migration\KeyHarborMigrationProvider;
use Base3\KeyHarbor\Migration\Migration001CreateCredentialTables;
use Base3\KeyHarbor\Migration\Migration002CredentialSubjects;
use Base3\KeyHarbor\Migration\Migration003ResourceConstraints;
use Base3\KeyHarbor\Repository\DatabaseCredentialRepository;
use Base3\KeyHarbor\Test\Service\ManagementTestRepository;
use PHPUnit\Framework\TestCase;

final class ResourceConstraintsMigrationTest extends TestCase {

	public function testThirdMigrationAddsOnlyResourceConstraintsToExistingGrantTable(): void {
		$database = new MigrationTestDatabase();
		$migration = new Migration003ResourceConstraints($database);

		self::assertSame('keyharbormigration003resourceconstraints', $migration::getName());
		self::assertSame('003', $migration->getVersion());
		self::assertStringContainsString('resource constraints', $migration->getDescription());
		$migration->up();

		self::assertTrue($database->connected());
		self::assertSame(['ALTER TABLE base3_keyharbor_grant ADD COLUMN constraints_json TEXT NULL'], $database->queries);
	}

	public function testProviderActivatesOnlyForDatabaseRepository(): void {
		$active = new KeyHarborMigrationProvider(new DatabaseCredentialRepository(new MigrationTestDatabase()));
		$inactive = new KeyHarborMigrationProvider(new ManagementTestRepository());

		self::assertTrue($active->isActive());
		self::assertFalse($inactive->isActive());
		self::assertSame('keyharbormigrationprovider', KeyHarborMigrationProvider::getName());
	}

	public function testProviderOwnsThreeOrderedMigrationSteps(): void {
		$provider = new KeyHarborMigrationProvider(new ManagementTestRepository());

		self::assertSame([
			Migration001CreateCredentialTables::class,
			Migration002CredentialSubjects::class,
			Migration003ResourceConstraints::class
		], $provider->getMigrations());
	}
}

require_once __DIR__ . '/MigrationTestDatabase.php';
require_once __DIR__ . '/../Service/ManagementTestRepository.php';
