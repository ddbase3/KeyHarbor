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

/** Versioned schema extension for credential principals and operation grants. */
final class Migration002CredentialSubjects implements IDatabaseMigration, ILegacyDatabaseMigration {

	public function __construct(private readonly IDatabase $database) {}

	public static function getName(): string {
		return 'keyharbormigration002credentialsubjects';
	}

	public function getVersion(): string {
		return '002';
	}

	public function getDescription(): string {
		return 'Add credential subjects and restricted service operations';
	}

	public function up(): void {
		$this->database->connect();
		$this->database->nonQuery("ALTER TABLE base3_keyharbor_key ADD COLUMN subject_type VARCHAR(16) NOT NULL DEFAULT 'user', ADD COLUMN subject_id VARCHAR(160) NOT NULL DEFAULT ''");
		$this->database->nonQuery("UPDATE base3_keyharbor_key SET subject_id=owner_user_id WHERE subject_type='user' AND subject_id=''");
		$this->database->nonQuery("ALTER TABLE base3_keyharbor_grant ADD COLUMN operations_json TEXT NULL");
	}

	public function down(): void {
		throw new MigrationException('Not implemented');
	}
}
