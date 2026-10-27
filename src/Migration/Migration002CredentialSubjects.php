<?php declare(strict_types=1);

namespace KeyHarbor\Migration;

use Base3\Database\Api\IDatabase;
use Base3\Migration\Api\IDatabaseMigration;

/** Versioned schema extension for credential principals and operation grants. */
final class Migration002CredentialSubjects implements IDatabaseMigration {

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
}
