<?php declare(strict_types=1);

namespace KeyHarbor\Service;

use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\User;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use CredentialFoundation\Dto\CredentialSubject;
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
		private readonly IAuthorizationService $authorization,
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

		return $this->authorization->decide(new AuthorizationRequest($this->authorization->getDefaultAuthority(), Permission::for('system', 'admin')))->isAllowed();
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
	 * Create a HMAC-only technical credential with explicitly selected operations.
	 * @param string $serviceSubjectId Stable, lower-case technical principal id.
	 * @param string $label Human-readable credential label.
	 * @param string $notificationAddress Expiration contact address (required if expiring).
	 * @param string $notificationLanguage Notification language.
	 * @param int|null $expiresAt Future expiry or null for no expiry.
	 * @param array<string,array<int,string>> $operationGrants Operation ids selected from discovered service definitions.
	 * @return IssuedCredential The credential plus one-time token.
	 * @throws CredentialManagementException If the current actor is not an administrator.
	 * @throws InvalidArgumentException If the subject or grants are invalid.
	 * Side effect: persists a new, independently revocable credential.
	 */
	public function createForService(
		string $serviceSubjectId,
		string $label,
		string $notificationAddress,
		string $notificationLanguage,
		?int $expiresAt,
		array $operationGrants,
		array $resourceConstraints = []
	): IssuedCredential {
		$this->requireAdmin();
		$actor = $this->getCurrentUser();
		$subject = new CredentialSubject(CredentialSubject::TYPE_SERVICE, $serviceSubjectId);
		$expiresAt = $this->normalizeExpiresAt($expiresAt);
		$grants = $this->normalizeOperationGrants($operationGrants);
		$constraints = $this->normalizeResourceConstraints($resourceConstraints, $grants);
		$generatedToken = $this->tokenService->generate();
		$encryptedSecret = $this->secretCipher->encrypt($generatedToken->getSecret());
		$ownerLogin = trim((string)($actor->userid ?? ''));
		if ($ownerLogin === '') {
			$ownerLogin = (string)$actor->id;
		}
		$credential = new ApiCredential(
			$generatedToken->getCredentialId(),
			$generatedToken->getPublicId(),
			(string)$actor->id,
			$ownerLogin,
			trim((string)($actor->name ?? '')) ?: $ownerLogin,
			$this->normalizeNotificationAddress($notificationAddress, (string)($actor->email ?? ''), $expiresAt),
			$this->normalizeNotificationLanguage($notificationLanguage, (string)($actor->lang ?? '')),
			$this->normalizeLabel($label),
			$generatedToken->getSecretHash(),
			true,
			$encryptedSecret->getCiphertext(),
			$encryptedSecret->getNonce(),
			time(), $expiresAt, null, null, null,
			array_keys($grants),
			$subject,
			$grants,
			$constraints
		);
		$this->repository->insert($credential);
		return new IssuedCredential($credential, $generatedToken);
	}

	/**
	 * Edit a service credential without changing its verified technical subject.
	 * @param string $credentialId Existing credential id.
	 * @param string $label Updated label.
	 * @param string $notificationAddress Updated notification address.
	 * @param string $notificationLanguage Updated notification language.
	 * @param int|null $expiresAt Expiry timestamp.
	 * @param array<string,array<int,string>> $operationGrants Entire replacement grant map.
	 * @return ApiCredential Updated credential retaining subject and secret.
	 * @throws CredentialManagementException If denied, revoked or missing.
	 * @throws InvalidArgumentException For invalid grants or metadata.
	 * Side effect: replaces credential metadata and grants.
	 */
	public function updateServiceAsAdmin(
		string $credentialId,
		string $label,
		string $notificationAddress,
		string $notificationLanguage,
		?int $expiresAt,
		array $operationGrants,
		array $resourceConstraints = []
	): ApiCredential {
		$this->requireAdmin();
		$credential = $this->requireServiceCredential($credentialId);
		if ($credential->isRevoked()) {
			throw new CredentialManagementException(CredentialManagementException::REVOKED, 'Revoked credentials cannot be edited.');
		}
		$grants = $this->normalizeOperationGrants($operationGrants);
		$constraints = $this->normalizeResourceConstraints($resourceConstraints, $grants);
		$expiresAt = $this->normalizeExpiresAt($expiresAt, $credential);
		$updated = $credential->withManagementData(
			$this->normalizeLabel($label),
			$this->normalizeNotificationAddress($notificationAddress, $credential->getNotificationAddress(), $expiresAt),
			$this->normalizeNotificationLanguage($notificationLanguage, $credential->getNotificationLanguage()),
			$expiresAt,
			array_keys($grants),
			$grants,
			$constraints
		);
		if (!$this->repository->update($updated)) {
			throw $this->notFound();
		}
		return $updated;
	}

	/**
	 * Rotate a technical HMAC credential, immediately invalidating its prior token.
	 * @param string $credentialId Existing service credential id.
	 * @return IssuedCredential New one-time token and updated credential.
	 * @throws CredentialManagementException If not authorized, revoked, expired or missing.
	 * Side effect: replaces stored HMAC secret and public id.
	 */
	public function rotateServiceAsAdmin(string $credentialId): IssuedCredential {
		$this->requireAdmin();
		$credential = $this->requireServiceCredential($credentialId);
		if ($credential->isRevoked()) {
			throw new CredentialManagementException(CredentialManagementException::REVOKED, 'Revoked credentials cannot be rotated.');
		}
		if ($credential->isExpired(time())) {
			throw new CredentialManagementException(CredentialManagementException::EXPIRED, 'Extend expiration before rotating an expired credential.');
		}
		$token = $this->tokenService->generateForCredential($credential->getId());
		$secret = $this->secretCipher->encrypt($token->getSecret());
		$updated = $credential->withRotatedSecret(
			$token->getPublicId(), $token->getSecretHash(), $secret->getCiphertext(), $secret->getNonce()
		);
		if (!$this->repository->update($updated)) {
			throw $this->notFound();
		}
		return new IssuedCredential($updated, $token);
	}

	private function requireServiceCredential(string $credentialId): ApiCredential {
		$credential = $this->repository->getById($this->normalizeCredentialId($credentialId));
		if ($credential === null || !$credential->isServiceCredential()) {
			throw $this->notFound();
		}
		return $credential;
	}

	/**
	 * @param array<string,array<int,string>> $operationGrants
	 * @return array<string,array<int,string>>
	 */
	/**
	 * @param array<string,array<string,array<string,mixed>>> $constraints
	 * @param array<string,array<int,string>> $grants
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	private function normalizeResourceConstraints(array $constraints, array $grants): array {
		$validator = new ResourceConstraintValidator();
		$normalized = [];
		foreach ($constraints as $serviceId => $operations) {
			if (!is_string($serviceId) || !isset($grants[$serviceId]) || !is_array($operations) || array_is_list($operations)) {
				throw new InvalidArgumentException('Resource constraints require a granted service.');
			}
			$service = $this->serviceCatalog->getService($serviceId);
			if ($service === null || $service->getResourceAttributes() === []) {
				throw new InvalidArgumentException('Service does not declare resource attributes.');
			}
			foreach ($operations as $operation => $expression) {
				if (!is_string($operation) || !in_array($operation, $grants[$serviceId], true) || !is_array($expression)) {
					throw new InvalidArgumentException('Resource constraint operation is not granted.');
				}
				$normalized[$serviceId][$operation] = $validator->validate($expression, $service);
			}
			ksort($normalized[$serviceId], SORT_STRING);
		}
		ksort($normalized, SORT_STRING);
		return $normalized;
	}

	private function normalizeOperationGrants(array $operationGrants): array {
		if ($operationGrants === []) {
			throw new InvalidArgumentException('Select at least one service with explicit operations.');
		}
		$normalized = [];
		foreach ($operationGrants as $serviceId => $operations) {
			if (!is_string($serviceId) || !is_array($operations) || !array_is_list($operations)) {
				throw new InvalidArgumentException('Service operation grants must be keyed lists.');
			}
			$service = $this->serviceCatalog->getService($serviceId);
			if ($service === null || $service->getOperations() === []) {
				throw new InvalidArgumentException('The service does not declare grantable operations.');
			}
			$accepted = [];
			foreach ($operations as $operation) {
				if (!is_string($operation) || !in_array($operation, $service->getOperations(), true)) {
					throw new InvalidArgumentException('Unknown service operation grant.');
				}
				$accepted[$operation] = true;
			}
			if ($accepted === []) {
				throw new InvalidArgumentException('Each service credential grant requires at least one operation.');
			}
			$normalized[$serviceId] = array_keys($accepted);
			sort($normalized[$serviceId], SORT_STRING);
		}
		ksort($normalized, SORT_STRING);
		return $normalized;
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
		if (!$this->authorization->decide(new AuthorizationRequest($this->authorization->getDefaultAuthority(), Permission::for('system', 'admin')))->isAllowed()) {
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
		if ($credential === null || $credential->isServiceCredential()) {
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
