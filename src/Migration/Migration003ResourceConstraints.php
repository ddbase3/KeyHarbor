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

namespace Base3\KeyHarbor\Migration;

use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Migration\Api\IDatabaseMigration;
use Base3\CoreFoundation\Migration\Api\ILegacyDatabaseMigration;
use Base3\CoreFoundation\Migration\Exception\MigrationException;

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
