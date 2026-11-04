<?php declare(strict_types=1);

namespace KeyHarbor\Test\Migration;

use KeyHarbor\Migration\KeyHarborMigrationProvider;
use KeyHarbor\Migration\Migration001CreateCredentialTables;
use KeyHarbor\Migration\Migration002CredentialSubjects;
use KeyHarbor\Migration\Migration003ResourceConstraints;
use KeyHarbor\Repository\DatabaseCredentialRepository;
use KeyHarbor\Test\Service\ManagementTestRepository;
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
