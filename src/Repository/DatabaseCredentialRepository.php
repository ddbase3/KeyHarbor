<?php declare(strict_types=1);

namespace KeyHarbor\Repository;

use Base3\Database\Api\IDatabase;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Model\ApiCredential;

/**
 * Database-backed credential repository.
 *
 * Schema creation is owned by KeyHarbor migrations. This repository never
 * creates or alters tables during normal runtime operations.
 */
final class DatabaseCredentialRepository implements ICredentialRepository {

	private const TABLE_KEY = 'base3_keyharbor_key';
	private const TABLE_GRANT = 'base3_keyharbor_grant';

	public function __construct(
		private readonly IDatabase $database
	) {}

	public function insert(ApiCredential $credential): void {
		$this->database->connect();
		$this->database->nonQuery(
			'INSERT INTO ' . self::TABLE_KEY . ' (' .
				'id, public_id, owner_user_id, owner_userid, owner_name, notification_address, notification_language, label, ' .
				'secret_hash, hmac_enabled, secret_ciphertext, secret_cipher_nonce, created_at, expires_at, revoked_at, ' .
				'warning_notified_at, expiry_notified_at' .
				') VALUES (' . $this->credentialValues($credential) . ')'
		);
		$this->insertGrants($credential);
	}

	public function getById(string $id): ?ApiCredential {
		return $this->getOne('id=' . $this->quote($id));
	}

	public function getByPublicId(string $publicId): ?ApiCredential {
		return $this->getOne('public_id=' . $this->quote($publicId));
	}

	public function getByOwner(string $id, int|string $ownerUserId): ?ApiCredential {
		return $this->getOne(
			'id=' . $this->quote($id) .
			' AND owner_user_id=' . $this->quote((string)$ownerUserId)
		);
	}

	public function listByOwner(int|string $ownerUserId): array {
		return $this->getMany(
			'SELECT * FROM ' . self::TABLE_KEY .
			' WHERE owner_user_id=' . $this->quote((string)$ownerUserId) .
			' ORDER BY created_at DESC, id ASC'
		);
	}

	public function listAll(): array {
		return $this->getMany(
			'SELECT * FROM ' . self::TABLE_KEY . ' ORDER BY created_at DESC, id ASC'
		);
	}

	public function update(ApiCredential $credential): bool {
		if ($this->getById($credential->getId()) === null) {
			return false;
		}

		return $this->performUpdate($credential, 'id=' . $this->quote($credential->getId()));
	}

	public function updateForOwner(ApiCredential $credential, int|string $ownerUserId): bool {
		$ownerUserId = (string)$ownerUserId;
		if ($this->getByOwner($credential->getId(), $ownerUserId) === null) {
			return false;
		}

		return $this->performUpdate(
			$credential,
			'id=' . $this->quote($credential->getId()) .
			' AND owner_user_id=' . $this->quote($ownerUserId)
		);
	}

	public function deleteRevoked(string $id): bool {
		$credential = $this->getById($id);
		if ($credential === null || !$credential->isRevoked()) {
			return false;
		}

		return $this->performDelete(
			$id,
			'id=' . $this->quote($id) . ' AND revoked_at IS NOT NULL'
		);
	}

	public function deleteRevokedForOwner(string $id, int|string $ownerUserId): bool {
		$ownerUserId = (string)$ownerUserId;
		$credential = $this->getByOwner($id, $ownerUserId);
		if ($credential === null || !$credential->isRevoked()) {
			return false;
		}

		return $this->performDelete(
			$id,
			'id=' . $this->quote($id) .
			' AND owner_user_id=' . $this->quote($ownerUserId) .
			' AND revoked_at IS NOT NULL'
		);
	}

	public function findExpiring(int $fromExclusive, int $toInclusive): array {
		$this->assertPositiveTimestamp($fromExclusive);
		$this->assertPositiveTimestamp($toInclusive);

		return $this->getMany(
			'SELECT * FROM ' . self::TABLE_KEY .
			' WHERE expires_at IS NOT NULL' .
			' AND expires_at > ' . $fromExclusive .
			' AND expires_at <= ' . $toInclusive .
			' AND revoked_at IS NULL' .
			' AND warning_notified_at IS NULL' .
			' ORDER BY expires_at ASC, id ASC'
		);
	}

	public function findExpired(int $now): array {
		$this->assertPositiveTimestamp($now);

		return $this->getMany(
			'SELECT * FROM ' . self::TABLE_KEY .
			' WHERE expires_at IS NOT NULL' .
			' AND expires_at <= ' . $now .
			' AND revoked_at IS NULL' .
			' AND expiry_notified_at IS NULL' .
			' ORDER BY expires_at ASC, id ASC'
		);
	}


	public function markWarningNotified(string $id, int $notifiedAt): bool {
		return $this->markNotification($id, 'warning_notified_at', $notifiedAt);
	}

	public function markExpiryNotified(string $id, int $notifiedAt): bool {
		return $this->markNotification($id, 'expiry_notified_at', $notifiedAt);
	}

	private function markNotification(string $id, string $column, int $notifiedAt): bool {
		if (!in_array($column, ['warning_notified_at', 'expiry_notified_at'], true)) {
			throw new \InvalidArgumentException('Unsupported credential notification column.');
		}
		$this->assertPositiveTimestamp($notifiedAt);
		$this->database->connect();
		$this->database->nonQuery(
			'UPDATE ' . self::TABLE_KEY . ' SET ' . $column . '=' . $notifiedAt .
			' WHERE id=' . $this->quote($id) .
			' AND ' . $column . ' IS NULL LIMIT 1'
		);

		$storedValue = $this->database->scalarQuery(
			'SELECT ' . $column . ' FROM ' . self::TABLE_KEY .
			' WHERE id=' . $this->quote($id) . ' LIMIT 1'
		);

		return is_numeric($storedValue) && (int)$storedValue === $notifiedAt;
	}

	private function getOne(string $where): ?ApiCredential {
		$this->database->connect();
		$row = $this->database->singleQuery(
			'SELECT * FROM ' . self::TABLE_KEY . ' WHERE ' . $where . ' LIMIT 1'
		);
		if (!is_array($row)) {
			return null;
		}

		$grants = $this->loadGrants([(string)$row['id']]);
		return $this->fromRow($row, $grants[(string)$row['id']] ?? []);
	}

	/**
	 * @return array<int,ApiCredential>
	 */
	private function getMany(string $query): array {
		$this->database->connect();
		$rows = $this->database->multiQuery($query);
		if ($rows === []) {
			return [];
		}

		$ids = array_map(fn(array $row): string => (string)$row['id'], $rows);
		$grants = $this->loadGrants($ids);

		return array_map(
			fn(array $row): ApiCredential => $this->fromRow(
				$row,
				$grants[(string)$row['id']] ?? []
			),
			$rows
		);
	}

	private function performUpdate(ApiCredential $credential, string $where): bool {
		$this->database->connect();
		$this->database->nonQuery(
			'UPDATE ' . self::TABLE_KEY . ' SET ' .
				'public_id=' . $this->quote($credential->getPublicId()) . ', ' .
				'owner_userid=' . $this->quote($credential->getOwnerLogin()) . ', ' .
				'owner_name=' . $this->quote($credential->getOwnerName()) . ', ' .
				'notification_address=' . $this->quote($credential->getNotificationAddress()) . ', ' .
				'notification_language=' . $this->quote($credential->getNotificationLanguage()) . ', ' .
				'label=' . $this->quote($credential->getLabel()) . ', ' .
				'secret_hash=' . $this->quote($credential->getSecretHash()) . ', ' .
				'hmac_enabled=' . ($credential->isHmacEnabled() ? '1' : '0') . ', ' .
				'secret_ciphertext=' . $this->quoteNullable($credential->getSecretCiphertext()) . ', ' .
				'secret_cipher_nonce=' . $this->quoteNullable($credential->getSecretCipherNonce()) . ', ' .
				'expires_at=' . $this->intNullable($credential->getExpiresAt()) . ', ' .
				'revoked_at=' . $this->intNullable($credential->getRevokedAt()) . ', ' .
				'warning_notified_at=' . $this->intNullable($credential->getWarningNotifiedAt()) . ', ' .
				'expiry_notified_at=' . $this->intNullable($credential->getExpiryNotifiedAt()) .
				' WHERE ' . $where . ' LIMIT 1'
		);
		$this->database->nonQuery(
			'DELETE FROM ' . self::TABLE_GRANT .
				' WHERE credential_id=' . $this->quote($credential->getId())
		);
		$this->insertGrants($credential);
		return true;
	}

	private function performDelete(string $id, string $where): bool {
		$this->database->connect();
		$this->database->nonQuery(
			'DELETE FROM ' . self::TABLE_GRANT .
				' WHERE credential_id=' . $this->quote($id)
		);
		$this->database->nonQuery(
			'DELETE FROM ' . self::TABLE_KEY . ' WHERE ' . $where . ' LIMIT 1'
		);

		return $this->getById($id) === null;
	}

	private function credentialValues(ApiCredential $credential): string {
		return implode(', ', [
			$this->quote($credential->getId()),
			$this->quote($credential->getPublicId()),
			$this->quote($credential->getOwnerUserId()),
			$this->quote($credential->getOwnerLogin()),
			$this->quote($credential->getOwnerName()),
			$this->quote($credential->getNotificationAddress()),
			$this->quote($credential->getNotificationLanguage()),
			$this->quote($credential->getLabel()),
			$this->quote($credential->getSecretHash()),
			$credential->isHmacEnabled() ? '1' : '0',
			$this->quoteNullable($credential->getSecretCiphertext()),
			$this->quoteNullable($credential->getSecretCipherNonce()),
			(string)$credential->getCreatedAt(),
			$this->intNullable($credential->getExpiresAt()),
			$this->intNullable($credential->getRevokedAt()),
			$this->intNullable($credential->getWarningNotifiedAt()),
			$this->intNullable($credential->getExpiryNotifiedAt())
		]);
	}

	private function insertGrants(ApiCredential $credential): void {
		foreach ($credential->getServiceIds() as $serviceId) {
			$this->database->nonQuery(
			'INSERT INTO ' . self::TABLE_GRANT . ' (credential_id, service_id, created_at) VALUES (' .
				$this->quote($credential->getId()) . ', ' .
				$this->quote($serviceId) . ', ' .
				$credential->getCreatedAt() . ')'
			);
		}
	}

	/**
	 * @param array<int,string> $credentialIds
	 * @return array<string,array<int,string>>
	 */
	private function loadGrants(array $credentialIds): array {
		if ($credentialIds === []) {
			return [];
		}

		$quotedIds = array_map(fn(string $id): string => $this->quote($id), $credentialIds);
		$rows = $this->database->multiQuery(
			'SELECT credential_id, service_id FROM ' . self::TABLE_GRANT .
			' WHERE credential_id IN (' . implode(', ', $quotedIds) . ')' .
			' ORDER BY service_id ASC'
		);

		$grants = [];
		foreach ($rows as $row) {
			$credentialId = (string)$row['credential_id'];
			$grants[$credentialId][] = (string)$row['service_id'];
		}
		return $grants;
	}

	/**
	 * @param array<int,string> $serviceIds
	 */
	private function fromRow(array $row, array $serviceIds): ApiCredential {
		return new ApiCredential(
			(string)$row['id'],
			(string)$row['public_id'],
			(string)$row['owner_user_id'],
			(string)($row['owner_userid'] ?? ''),
			(string)($row['owner_name'] ?? ''),
			(string)($row['notification_address'] ?? ''),
			(string)($row['notification_language'] ?? ''),
			(string)$row['label'],
			(string)$row['secret_hash'],
			((int)($row['hmac_enabled'] ?? 0)) === 1,
			$this->nullableString($row['secret_ciphertext'] ?? null),
			$this->nullableString($row['secret_cipher_nonce'] ?? null),
			(int)$row['created_at'],
			$this->nullableInt($row['expires_at'] ?? null),
			$this->nullableInt($row['revoked_at'] ?? null),
			$this->nullableInt($row['warning_notified_at'] ?? null),
			$this->nullableInt($row['expiry_notified_at'] ?? null),
			$serviceIds
		);
	}

	private function quote(string $value): string {
		return "'" . $this->database->escape($value) . "'";
	}

	private function quoteNullable(?string $value): string {
		return $value === null ? 'NULL' : $this->quote($value);
	}

	private function intNullable(?int $value): string {
		return $value === null ? 'NULL' : (string)$value;
	}

	private function nullableString(mixed $value): ?string {
		return $value === null ? null : (string)$value;
	}

	private function nullableInt(mixed $value): ?int {
		return $value === null ? null : (int)$value;
	}

	private function assertPositiveTimestamp(int $timestamp): void {
		if ($timestamp <= 0) {
			throw new \InvalidArgumentException('Credential query timestamps must be positive.');
		}
	}
}
