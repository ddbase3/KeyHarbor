<?php declare(strict_types=1);

namespace KeyHarbor\Migration;

use Base3\Database\Api\IDatabase;
use Base3\Migration\Api\IDatabaseMigration;

/**
 * Creates the immutable KeyHarbor credential and grant schema.
 */
final class Migration001CreateCredentialTables implements IDatabaseMigration {

	public function __construct(
		private readonly IDatabase $database
	) {}

	public static function getName(): string {
		return 'keyharbormigration001createcredentialtables';
	}

	public function getVersion(): string {
		return '001';
	}

	public function getDescription(): string {
		return 'Create KeyHarbor credential and service grant tables';
	}

	public function up(): void {
		$this->database->connect();

		$this->database->nonQuery(<<<SQL
CREATE TABLE base3_keyharbor_key (
	id VARCHAR(64) NOT NULL PRIMARY KEY,
	public_id VARCHAR(64) NOT NULL,
	owner_user_id VARCHAR(160) NOT NULL,
	owner_userid VARCHAR(255) NOT NULL DEFAULT '',
	owner_name VARCHAR(255) NOT NULL DEFAULT '',
	notification_address VARCHAR(512) NOT NULL DEFAULT '',
	notification_language VARCHAR(24) NOT NULL DEFAULT '',
	label VARCHAR(255) NOT NULL,
	secret_hash CHAR(64) NOT NULL,
	hmac_enabled TINYINT(1) NOT NULL DEFAULT 0,
	secret_ciphertext LONGTEXT NULL,
	secret_cipher_nonce VARCHAR(255) NULL,
	created_at BIGINT UNSIGNED NOT NULL,
	expires_at BIGINT UNSIGNED NULL,
	revoked_at BIGINT UNSIGNED NULL,
	warning_notified_at BIGINT UNSIGNED NULL,
	expiry_notified_at BIGINT UNSIGNED NULL,
	UNIQUE KEY uq_base3_keyharbor_public_id (public_id),
	KEY idx_base3_keyharbor_owner (owner_user_id, created_at),
	KEY idx_base3_keyharbor_expiring (expires_at, revoked_at, warning_notified_at),
	KEY idx_base3_keyharbor_expired (expires_at, revoked_at, expiry_notified_at)
)
SQL);

		$this->database->nonQuery(<<<SQL
CREATE TABLE base3_keyharbor_grant (
	credential_id VARCHAR(64) NOT NULL,
	service_id VARCHAR(190) NOT NULL,
	created_at BIGINT UNSIGNED NOT NULL,
	PRIMARY KEY (credential_id, service_id),
	KEY idx_base3_keyharbor_grant_service (service_id, credential_id)
)
SQL);
	}
}
