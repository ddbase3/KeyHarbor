<?php declare(strict_types=1);

namespace KeyHarbor\Api;

use KeyHarbor\Model\ApiCredential;

/**
 * Persists user- and service-subject API credentials and their service grants.
 *
 * This interface is a storage contract, not a permission decision service.
 * Business services enforce authorization before invoking unrestricted methods.
 * Owner-scoped operations MUST select only user subjects and enforce the owner
 * id in the storage query, never filter unrestricted results in memory.
 */
interface ICredentialRepository {

	/**
	 * Persist a new credential and its complete grant map.
	 * @param ApiCredential $credential New immutable credential with valid subject.
	 * @return void
	 * @throws \Throwable If storage rejects the insertion; never silently discard grants.
	 * Side effect: creates credential and grant rows, including encrypted HMAC material when applicable.
	 */
	public function insert(ApiCredential $credential): void;

	/**
	 * Find any credential by its internal id; caller must authorize access.
	 * @param string $id Internal credential id.
	 * @return ApiCredential|null Credential or null when absent.
	 * @throws \Throwable If storage is unavailable or persisted grant data is invalid.
	 * Invariant: result contains the stored subject and exact operation grants.
	 */
	public function getById(string $id): ?ApiCredential;

	/**
	 * Find any credential by its public, nonsecret lookup id.
	 * @param string $publicId Public id extracted from a presented token.
	 * @return ApiCredential|null Credential or null when absent.
	 * @throws \Throwable If storage is unavailable.
	 * Invariant: lookup does not authenticate the presented token secret.
	 */
	public function getByPublicId(string $publicId): ?ApiCredential;

	/**
	 * Find a **user-subject** credential belonging to a verified owner.
	 * @param string $id Internal credential id.
	 * @param int|string $ownerUserId Trusted current user id.
	 * @return ApiCredential|null Matching user credential or null; never a service credential.
	 * @throws \Throwable If storage is unavailable.
	 * Invariant: both subject_type=user and owner_user_id must be constrained in the query.
	 */
	public function getByOwner(string $id, int|string $ownerUserId): ?ApiCredential;

	/**
	 * List user-subject credentials managed by a trusted current user.
	 * @param int|string $ownerUserId Trusted current user id.
	 * @return array<int,ApiCredential> User credentials only, or empty list.
	 * @throws \Throwable If storage is unavailable.
	 * Invariant: service-subject credentials are never returned to personal management.
	 */
	public function listByOwner(int|string $ownerUserId): array;

	/**
	 * List all credentials for a separately authorized administration operation.
	 * @return array<int,ApiCredential> All user and service subjects.
	 * @throws \Throwable If storage is unavailable.
	 * Invariant: repository does not make the administrative authorization decision.
	 */
	public function listAll(): array;

	/**
	 * Replace a credential and its grants for an already authorized administrator.
	 * @param ApiCredential $credential Updated immutable record.
	 * @return bool True when present and updated; false if id does not exist.
	 * @throws \Throwable If storage fails.
	 * Side effect: writes metadata, subject, secret material and complete grant map.
	 */
	public function update(ApiCredential $credential): bool;

	/**
	 * Replace only an owned user-subject credential.
	 * @param ApiCredential $credential Updated user-subject credential.
	 * @param int|string $ownerUserId Verified current user id.
	 * @return bool False when absent or not owned; otherwise true.
	 * @throws \Throwable If storage fails.
	 * Invariant: service-subject credentials cannot be modified by owner-scoped operations.
	 */
	public function updateForOwner(ApiCredential $credential, int|string $ownerUserId): bool;

	/**
	 * Permanently remove a credential that was explicitly revoked first.
	 * @param string $id Credential id.
	 * @return bool True if deleted; false if missing or not revoked.
	 * @throws \Throwable If storage fails.
	 * Side effect: deletes the credential and its service/operation grants.
	 */
	public function deleteRevoked(string $id): bool;

	/**
	 * Permanently delete only a revoked user credential managed by its owner.
	 * @param string $id Credential id.
	 * @param int|string $ownerUserId Verified current user id.
	 * @return bool True if deleted; false if absent, unowned, revoked=false or service subject.
	 * @throws \Throwable If storage fails.
	 * Invariant: service credentials cannot be deleted by user-scoped operations.
	 */
	public function deleteRevokedForOwner(string $id, int|string $ownerUserId): bool;

	/**
	 * Find not-yet-warned active credentials with upcoming expiration.
	 * @param int $fromExclusive Lower exclusive timestamp, positive.
	 * @param int $toInclusive Upper inclusive timestamp, positive.
	 * @return array<int,ApiCredential> Eligible user and service credentials.
	 * @throws \InvalidArgumentException For nonpositive timestamps.
	 * @throws \Throwable If storage fails.
	 */
	public function findExpiring(int $fromExclusive, int $toInclusive): array;

	/**
	 * Find not-yet-notified credentials whose expiration has passed.
	 * @param int $now Current positive timestamp.
	 * @return array<int,ApiCredential> Eligible user and service credentials.
	 * @throws \InvalidArgumentException For nonpositive timestamp.
	 * @throws \Throwable If storage fails.
	 */
	public function findExpired(int $now): array;

	/**
	 * Mark the first due expiration warning for the provided credential.
	 * @param string $id Credential id.
	 * @param int $notifiedAt Positive queue/dispatch timestamp.
	 * @return bool True if stored marker equals requested timestamp, false if missing/conflicting.
	 * @throws \Throwable If storage fails.
	 * Side effect: sets warning notification marker at most once.
	 */
	public function markWarningNotified(string $id, int $notifiedAt): bool;

	/**
	 * Mark the first expiry notification for the provided credential.
	 * @param string $id Credential id.
	 * @param int $notifiedAt Positive queue/dispatch timestamp.
	 * @return bool True if stored marker equals requested timestamp, false if missing/conflicting.
	 * @throws \Throwable If storage fails.
	 * Side effect: sets expiry notification marker at most once.
	 */
	public function markExpiryNotified(string $id, int $notifiedAt): bool;
}
