<?php declare(strict_types=1);

namespace KeyHarbor\Service;

use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3\Usermanager\User;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use InvalidArgumentException;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Dto\IssuedCredential;
use KeyHarbor\Exception\CredentialManagementException;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;

/**
 * Applies actor, ownership and validation rules for credential management.
 */
final class CredentialManagementService {

	public function __construct(
		private readonly IUsermanager $usermanager,
		private readonly ICredentialRepository $repository,
		private readonly CredentialTokenService $tokenService,
		private readonly CredentialServiceCatalog $serviceCatalog,
		private readonly CredentialSecretCipher $secretCipher
	) {}

	public function getCurrentUser(): User {
		$user = $this->usermanager->getUser();
		if (!$user instanceof User || trim((string)$user->id) === '') {
			throw new CredentialManagementException(
				CredentialManagementException::NOT_AUTHENTICATED,
				'An authenticated user is required.'
			);
		}

		return $user;
	}

	public function assertCurrentUserAdmin(): void {
		$this->requireAdmin();
	}

	public function isCurrentUserAdmin(): bool {
		$user = $this->usermanager->getUser();
		if (!$user instanceof User || trim((string)$user->id) === '') {
			return false;
		}

		return $this->usermanager->can(Permission::for('system', 'admin'));
	}

	/**
	 * @return array<int,CredentialServiceDefinition>
	 */
	public function getAvailableServices(): array {
		return $this->serviceCatalog->getServices();
	}

	/**
	 * @return array<int,ApiCredential>
	 */
	public function listForCurrentUser(): array {
		$user = $this->getCurrentUser();
		return $this->repository->listByOwner((string)$user->id);
	}

	/**
	 * @return array<int,ApiCredential>
	 */
	public function listAllForAdmin(): array {
		$this->requireAdmin();
		return $this->repository->listAll();
	}

	/**
	 * @param array<int,mixed> $serviceIds
	 */
	public function createForCurrentUser(
		string $label,
		string $notificationAddress,
		string $notificationLanguage,
		?int $expiresAt,
		array $serviceIds,
		string $authenticationMode = 'bearer'
	): IssuedCredential {
		$user = $this->getCurrentUser();
		$label = $this->normalizeLabel($label);
		$expiresAt = $this->normalizeExpiresAt($expiresAt);
		$notificationAddress = $this->normalizeNotificationAddress(
			$notificationAddress,
			(string)($user->email ?? ''),
			$expiresAt
		);
		$notificationLanguage = $this->normalizeNotificationLanguage(
			$notificationLanguage,
			(string)($user->lang ?? '')
		);
		$serviceIds = $this->normalizeServiceIds($serviceIds);
		$authenticationMode = $this->normalizeAuthenticationMode($authenticationMode);
		$generatedToken = $this->tokenService->generate();
		$encryptedSecret = $authenticationMode === 'hmac'
			? $this->secretCipher->encrypt($generatedToken->getSecret())
			: null;
		$ownerLogin = trim((string)($user->userid ?? ''));
		if ($ownerLogin === '') {
			$ownerLogin = (string)$user->id;
		}
		$ownerName = trim((string)($user->name ?? ''));
		if ($ownerName === '') {
			$ownerName = $ownerLogin;
		}

		$credential = new ApiCredential(
			$generatedToken->getCredentialId(),
			$generatedToken->getPublicId(),
			(string)$user->id,
			$ownerLogin,
			$ownerName,
			$notificationAddress,
			$notificationLanguage,
			$label,
			$generatedToken->getSecretHash(),
			$authenticationMode === 'hmac',
			$encryptedSecret?->getCiphertext(),
			$encryptedSecret?->getNonce(),
			time(),
			$expiresAt,
			null,
			null,
			null,
			$serviceIds
		);

		$this->repository->insert($credential);
		return new IssuedCredential($credential, $generatedToken);
	}

	/**
	 * @param array<int,mixed> $serviceIds
	 */
	public function updateForCurrentUser(
		string $credentialId,
		string $label,
		string $notificationAddress,
		string $notificationLanguage,
		?int $expiresAt,
		array $serviceIds
	): ApiCredential {
		$user = $this->getCurrentUser();
		$credential = $this->requireOwnedCredential($credentialId, (string)$user->id);
		if ($credential->isRevoked()) {
			throw new CredentialManagementException(
				CredentialManagementException::REVOKED,
				'Revoked credentials cannot be edited.'
			);
		}

		$expiresAt = $this->normalizeExpiresAt($expiresAt, $credential);
		$updated = $credential->withManagementData(
			$this->normalizeLabel($label),
			$this->normalizeNotificationAddress(
				$notificationAddress,
				(string)($user->email ?? ''),
				$expiresAt
			),
			$this->normalizeNotificationLanguage(
				$notificationLanguage,
				(string)($user->lang ?? '')
			),
			$expiresAt,
			$this->normalizeServiceIds($serviceIds, $credential)
		);

		if (!$this->repository->updateForOwner($updated, (string)$user->id)) {
			throw $this->notFound();
		}

		return $updated;
	}

	public function rotateForCurrentUser(string $credentialId): IssuedCredential {
		$user = $this->getCurrentUser();
		$credential = $this->requireOwnedCredential($credentialId, (string)$user->id);
		if ($credential->isRevoked()) {
			throw new CredentialManagementException(
				CredentialManagementException::REVOKED,
				'Revoked credentials cannot be rotated.'
			);
		}
		if ($credential->isExpired(time())) {
			throw new CredentialManagementException(
				CredentialManagementException::EXPIRED,
				'Extend the expiration before rotating an expired credential.'
			);
		}
		$generatedToken = $this->tokenService->generateForCredential($credential->getId());
		$encryptedSecret = $credential->isHmacEnabled()
			? $this->secretCipher->encrypt($generatedToken->getSecret())
			: null;
		$rotated = $credential->withRotatedSecret(
			$generatedToken->getPublicId(),
			$generatedToken->getSecretHash(),
			$encryptedSecret?->getCiphertext(),
			$encryptedSecret?->getNonce()
		);

		if (!$this->repository->updateForOwner($rotated, (string)$user->id)) {
			throw $this->notFound();
		}

		return new IssuedCredential($rotated, $generatedToken);
	}

	public function revokeForCurrentUser(string $credentialId): ApiCredential {
		$user = $this->getCurrentUser();
		$credential = $this->requireOwnedCredential($credentialId, (string)$user->id);
		if ($credential->isRevoked()) {
			return $credential;
		}

		$revoked = $credential->withRevokedAt(time());
		if (!$this->repository->updateForOwner($revoked, (string)$user->id)) {
			throw $this->notFound();
		}

		return $revoked;
	}

	public function revokeAsAdmin(string $credentialId): ApiCredential {
		$this->requireAdmin();
		$credential = $this->repository->getById($this->normalizeCredentialId($credentialId));
		if ($credential === null) {
			throw $this->notFound();
		}
		if ($credential->isRevoked()) {
			return $credential;
		}

		$revoked = $credential->withRevokedAt(time());
		if (!$this->repository->update($revoked)) {
			throw $this->notFound();
		}

		return $revoked;
	}

	public function deleteForCurrentUser(string $credentialId): void {
		$user = $this->getCurrentUser();
		$credential = $this->requireOwnedCredential($credentialId, (string)$user->id);
		$this->assertRevokedForDeletion($credential);

		if (!$this->repository->deleteRevokedForOwner($credential->getId(), (string)$user->id)) {
			throw $this->notFound();
		}
	}

	public function deleteAsAdmin(string $credentialId): void {
		$this->requireAdmin();
		$credential = $this->repository->getById($this->normalizeCredentialId($credentialId));
		if ($credential === null) {
			throw $this->notFound();
		}
		$this->assertRevokedForDeletion($credential);

		if (!$this->repository->deleteRevoked($credential->getId())) {
			throw $this->notFound();
		}
	}

	private function assertRevokedForDeletion(ApiCredential $credential): void {
		if (!$credential->isRevoked()) {
			throw new CredentialManagementException(
				CredentialManagementException::NOT_REVOKED,
				'Revoke the credential before deleting it.'
			);
		}
	}

	private function requireAdmin(): void {
		$this->getCurrentUser();
		if (!$this->usermanager->can(Permission::for('system', 'admin'))) {
			throw new CredentialManagementException(
				CredentialManagementException::ACCESS_DENIED,
				'System administrator permission is required.'
			);
		}
	}

	private function requireOwnedCredential(string $credentialId, string $ownerUserId): ApiCredential {
		$credential = $this->repository->getByOwner(
			$this->normalizeCredentialId($credentialId),
			$ownerUserId
		);
		if ($credential === null) {
			throw $this->notFound();
		}

		return $credential;
	}

	private function normalizeAuthenticationMode(string $authenticationMode): string {
		$authenticationMode = strtolower(trim($authenticationMode));
		if (!in_array($authenticationMode, ['bearer', 'hmac'], true)) {
			throw new InvalidArgumentException('Credential authentication mode must be bearer or hmac.');
		}

		return $authenticationMode;
	}

	private function normalizeCredentialId(string $credentialId): string {
		$credentialId = trim($credentialId);
		if (!preg_match('/^[a-f0-9]{32}$/', $credentialId)) {
			throw new InvalidArgumentException('Credential ids must contain 32 lowercase hexadecimal characters.');
		}
		return $credentialId;
	}

	private function normalizeLabel(string $label): string {
		$label = trim($label);
		if ($label === '') {
			throw new InvalidArgumentException('Credential label is required.');
		}
		if (strlen($label) > 255) {
			throw new InvalidArgumentException('Credential label must not exceed 255 bytes.');
		}
		$this->assertNoControlCharacters($label, 'Credential label');
		return $label;
	}

	private function normalizeNotificationAddress(
		string $notificationAddress,
		string $defaultAddress,
		?int $expiresAt
	): string {
		$notificationAddress = trim($notificationAddress);
		if ($notificationAddress === '') {
			$notificationAddress = trim($defaultAddress);
		}
		if ($expiresAt !== null && $notificationAddress === '') {
			throw new InvalidArgumentException(
				'An expiring credential requires a notification address.'
			);
		}
		if (strlen($notificationAddress) > 512) {
			throw new InvalidArgumentException('Notification address must not exceed 512 bytes.');
		}
		$this->assertNoControlCharacters($notificationAddress, 'Notification address');
		return $notificationAddress;
	}

	private function normalizeNotificationLanguage(string $language, string $defaultLanguage): string {
		$language = trim($language);
		if ($language === '') {
			$language = trim($defaultLanguage);
		}
		if ($language === '') {
			$language = 'en';
		}
		if (strlen($language) > 24 || !preg_match('/^[A-Za-z0-9_-]+$/', $language)) {
			throw new InvalidArgumentException('Notification language contains unsupported characters.');
		}
		return $language;
	}

	private function normalizeExpiresAt(?int $expiresAt, ?ApiCredential $existing = null): ?int {
		if ($expiresAt === null) {
			return null;
		}
		if ($expiresAt <= time() && ($existing === null || $expiresAt !== $existing->getExpiresAt())) {
			throw new InvalidArgumentException('Credential expiration must be in the future.');
		}
		return $expiresAt;
	}

	/**
	 * @param array<int,mixed> $serviceIds
	 * @return array<int,string>
	 */
	private function normalizeServiceIds(array $serviceIds, ?ApiCredential $existing = null): array {
		$normalized = [];
		foreach ($serviceIds as $serviceId) {
			if (!is_scalar($serviceId)) {
				throw new InvalidArgumentException('Credential service ids must be scalar values.');
			}
			$serviceId = trim((string)$serviceId);
			if ($serviceId === '') {
				continue;
			}
			if (!$this->serviceCatalog->hasService($serviceId) &&
				($existing === null || !$existing->hasService($serviceId))) {
				throw new InvalidArgumentException('Unknown credential service: ' . $serviceId);
			}
			$normalized[$serviceId] = true;
		}
		if ($normalized === []) {
			throw new InvalidArgumentException('Select at least one credential service.');
		}

		$serviceIds = array_keys($normalized);
		sort($serviceIds, SORT_STRING);
		return $serviceIds;
	}

	private function assertNoControlCharacters(string $value, string $field): void {
		if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
			throw new InvalidArgumentException($field . ' contains unsupported control characters.');
		}
	}

	private function notFound(): CredentialManagementException {
		return new CredentialManagementException(
			CredentialManagementException::NOT_FOUND,
			'Credential not found.'
		);
	}
}
