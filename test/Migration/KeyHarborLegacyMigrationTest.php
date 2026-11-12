<?php declare(strict_types=1);

namespace KeyHarbor\Test\Migration;

use Base3\Migration\Api\IDatabaseMigration;
use Base3\Migration\Api\ILegacyDatabaseMigration;
use Base3\Migration\Exception\MigrationException;
use KeyHarbor\Migration\Migration001CreateCredentialTables;
use KeyHarbor\Migration\Migration002CredentialSubjects;
use KeyHarbor\Migration\Migration003ResourceConstraints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KeyHarborLegacyMigrationTest extends TestCase {

	/**
	 * @return iterable<string, array{class-string<IDatabaseMigration>}>
	 */
	public static function historicalMigrations(): iterable {
		yield 'migration001createcredentialtables' => [Migration001CreateCredentialTables::class];
		yield 'migration002credentialsubjects' => [Migration002CredentialSubjects::class];
		yield 'migration003resourceconstraints' => [Migration003ResourceConstraints::class];
	}

	#[DataProvider('historicalMigrations')]
	public function testHistoricalMigrationIsChecksumExemptAndRejectsDown(string $migrationClass): void {
		self::assertTrue(is_subclass_of($migrationClass, IDatabaseMigration::class));
		self::assertTrue(is_subclass_of($migrationClass, ILegacyDatabaseMigration::class));

		// The reverse operation must fail before any constructor dependency is needed.
		$migration = (new ReflectionClass($migrationClass))->newInstanceWithoutConstructor();
		$this->expectException(MigrationException::class);
		$this->expectExceptionMessage('Not implemented');
		$migration->down();
	}
}
