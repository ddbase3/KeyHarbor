<?php declare(strict_types=1);

namespace KeyHarbor\Api;

use KeyHarbor\Model\ApiCredential;

/**
 * Persists API credentials and their service grants.
 *
 * Ownership-aware operations must enforce the owner id in the storage query.
 * Business services should use these methods for all user-scoped access.
 */
interface ICredentialRepository {

	public function insert(ApiCredential $credential): void;

	public function getById(string $id): ?ApiCredential;

	public function getByPublicId(string $publicId): ?ApiCredential;

	public function getByOwner(string $id, int|string $ownerUserId): ?ApiCredential;

	/**
	 * @return array<int,ApiCredential>
	 */
	public function listByOwner(int|string $ownerUserId): array;

	/**
	 * @return array<int,ApiCredential>
	 */
	public function listAll(): array;

	public function update(ApiCredential $credential): bool;

	public function updateForOwner(ApiCredential $credential, int|string $ownerUserId): bool;

	/**
	 * Permanently removes a revoked credential and its grants.
	 */
	public function deleteRevoked(string $id): bool;

	/**
	 * Permanently removes a revoked credential owned by the given user.
	 */
	public function deleteRevokedForOwner(string $id, int|string $ownerUserId): bool;

	/**
	 * Returns credentials whose warning is due in the given interval.
	 *
	 * @return array<int,ApiCredential>
	 */
	public function findExpiring(int $fromExclusive, int $toInclusive): array;

	/**
	 * Returns credentials whose expiry notification is due.
	 *
	 * @return array<int,ApiCredential>
	 */
	public function findExpired(int $now): array;

	/**
	 * Marks one warning notification as queued if it has not been marked before.
	 */
	public function markWarningNotified(string $id, int $notifiedAt): bool;

	/**
	 * Marks one expiry notification as queued if it has not been marked before.
	 */
	public function markExpiryNotified(string $id, int $notifiedAt): bool;
}
