<?php declare(strict_types=1);

namespace KeyHarbor\Migration;

use Base3\Database\Api\IDatabase;
use Base3\Migration\Api\IDatabaseMigration;
use Base3\Migration\Api\ILegacyDatabaseMigration;
use Base3\Migration\Exception\MigrationException;

/** Versioned extension of the existing credential grant table. */
final class Migration003ResourceConstraints implements IDatabaseMigration, ILegacyDatabaseMigration {

	public function __construct(private readonly IDatabase $database) {}

	public static function getName(): string {
		return 'keyharbormigration003resourceconstraints';
	}

	public function getVersion(): string {
		return '003';
	}

	public function getDescription(): string {
		return 'Add per-operation declarative resource constraints to credential grants';
	}

	public function up(): void {
		$this->database->connect();
		$this->database->nonQuery('ALTER TABLE base3_keyharbor_grant ADD COLUMN constraints_json TEXT NULL');
	}

	public function down(): void {
		throw new MigrationException('Not implemented');
	}
}
