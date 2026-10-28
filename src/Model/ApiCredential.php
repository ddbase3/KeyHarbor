<?php declare(strict_types=1);

namespace KeyHarbor\Model;

use InvalidArgumentException;
use CredentialFoundation\Dto\CredentialSubject;

/**
 * Immutable persisted API credential including service grants.
 */
final class ApiCredential {

	private const SERVICE_ID_PATTERN = '/^[a-z0-9][a-z0-9._:-]*$/';

	/** @var array<int,string> */
	private readonly array $serviceIds;

	/** @var array<string,array<int,string>> */
	private readonly array $operationGrants;

	/** @var array<string,array<string,array<string,mixed>>> */
	private readonly array $resourceConstraints;

	private readonly CredentialSubject $subject;

	/**
	 * @param array<int,string> $serviceIds
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $publicId,
		private readonly string $ownerUserId,
		private readonly string $ownerLogin,
		private readonly string $ownerName,
		private readonly string $notificationAddress,
		private readonly string $notificationLanguage,
		private readonly string $label,
		private readonly string $secretHash,
		private readonly bool $hmacEnabled,
		private readonly ?string $secretCiphertext,
		private readonly ?string $secretCipherNonce,
		private readonly int $createdAt,
		private readonly ?int $expiresAt,
		private readonly ?int $revokedAt,
		private readonly ?int $warningNotifiedAt,
		private readonly ?int $expiryNotifiedAt,
		array $serviceIds,
		?CredentialSubject $subject = null,
		array $operationGrants = [],
		array $resourceConstraints = []
	) {
		if (!preg_match('/^[a-f0-9]{32}$/', $this->id)) {
			throw new InvalidArgumentException('Credential ids must contain 32 lowercase hexadecimal characters.');
		}
		if (!preg_match('/^[a-f0-9]{20}$/', $this->publicId)) {
			throw new InvalidArgumentException('Credential public ids must contain 20 lowercase hexadecimal characters.');
		}
		if (trim($this->ownerUserId) === '') {
			throw new InvalidArgumentException('Credential owner user ids must not be empty.');
		}
		$this->subject = $subject ?? new CredentialSubject(CredentialSubject::TYPE_USER, $this->ownerUserId);
		if ($this->subject->isUser() && $this->subject->getId() !== $this->ownerUserId) {
			throw new InvalidArgumentException('A user credential subject must match the credential owner.');
		}
		if ($this->subject->isService() && !$this->hmacEnabled) {
			throw new InvalidArgumentException('Service credentials require HMAC authentication.');
		}
		if (trim($this->label) === '') {
			throw new InvalidArgumentException('Credential labels must not be empty.');
		}
		if (!preg_match('/^[a-f0-9]{64}$/', $this->secretHash)) {
			throw new InvalidArgumentException('Credential secret hashes must contain 64 lowercase hexadecimal characters.');
		}
		if ($this->createdAt <= 0) {
			throw new InvalidArgumentException('Credential creation timestamps must be positive.');
		}
		foreach ([$this->expiresAt, $this->revokedAt, $this->warningNotifiedAt, $this->expiryNotifiedAt] as $timestamp) {
			if ($timestamp !== null && $timestamp <= 0) {
				throw new InvalidArgumentException('Credential lifecycle timestamps must be positive when present.');
			}
		}
		if ($this->hmacEnabled) {
			if (trim((string)$this->secretCiphertext) === '' || trim((string)$this->secretCipherNonce) === '') {
				throw new InvalidArgumentException('HMAC-enabled credentials require encrypted secret material.');
			}
		} elseif ($this->secretCiphertext !== null || $this->secretCipherNonce !== null) {
			throw new InvalidArgumentException('Bearer-only credentials must not retain encrypted secret material.');
		}

		$normalizedServiceIds = [];
		foreach ($serviceIds as $serviceId) {
			$serviceId = trim((string)$serviceId);
			if (!preg_match(self::SERVICE_ID_PATTERN, $serviceId)) {
				throw new InvalidArgumentException('Credential service ids contain unsupported characters.');
			}
			$normalizedServiceIds[$serviceId] = true;
		}
		if ($normalizedServiceIds === []) {
			throw new InvalidArgumentException('Credentials must grant at least one service.');
		}

		$serviceIds = array_keys($normalizedServiceIds);
		sort($serviceIds, SORT_STRING);
		$this->serviceIds = $serviceIds;

		$normalizedOperations = [];
		foreach ($operationGrants as $serviceId => $operations) {
			if (!is_string($serviceId) || !in_array($serviceId, $serviceIds, true) || !is_array($operations)) {
				throw new InvalidArgumentException('Operation grants must belong to selected services.');
			}
			$unique = [];
			foreach ($operations as $operation) {
				if (!is_string($operation) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $operation)) {
					throw new InvalidArgumentException('Credential operation grants contain an invalid operation.');
				}
				$unique[$operation] = true;
			}
			if ($this->subject->isService() && $unique === []) {
				throw new InvalidArgumentException('Service credentials require explicit operations for every service.');
			}
			$normalizedOperations[$serviceId] = array_keys($unique);
			sort($normalizedOperations[$serviceId], SORT_STRING);
		}
		if ($this->subject->isService()) {
			foreach ($serviceIds as $serviceId) {
				if (!isset($normalizedOperations[$serviceId])) {
					throw new InvalidArgumentException('Service credentials require explicit operations for every service.');
				}
			}
		}
		ksort($normalizedOperations, SORT_STRING);
		$this->operationGrants = $normalizedOperations;
		foreach ($resourceConstraints as $serviceId => $byOperation) {
			if (!isset($normalizedOperations[$serviceId]) || !is_array($byOperation)) {
				throw new InvalidArgumentException('Resource constraints require a granted service.');
			}
			foreach ($byOperation as $operation => $constraint) {
				if (!in_array($operation, $normalizedOperations[$serviceId], true) || !is_array($constraint)) {
					throw new InvalidArgumentException('Resource constraints require a granted operation.');
				}
			}
		}
		$this->resourceConstraints = $resourceConstraints;
	}

	public function getId(): string {
		return $this->id;
	}

	public function getPublicId(): string {
		return $this->publicId;
	}

	/**
	 * Return the credential's authenticated principal, not the issuing administrator.
	 */
	public function getSubject(): CredentialSubject {
		return $this->subject;
	}

	public function isServiceCredential(): bool {
		return $this->subject->isService();
	}

	/** @return array<string,array<int,string>> Exact per-service operation grants. */
	public function getOperationGrants(): array {
		return $this->operationGrants;
	}

	/** @return array<string,array<string,array<string,mixed>>> */
	public function getResourceConstraints(): array {
		return $this->resourceConstraints;
	}

	/** @return array<string,mixed>|null */
	public function getResourceConstraint(string $serviceId, string $operation): ?array {
		return $this->resourceConstraints[$serviceId][$operation] ?? null;
	}

	public function hasOperation(string $serviceId, string $operation): bool {
		if (!$this->hasService($serviceId)) {
			return false;
		}
		if (!isset($this->operationGrants[$serviceId])) {
			return $this->subject->isUser();
		}
		return in_array($operation, $this->operationGrants[$serviceId], true);
	}

	public function getOwnerUserId(): string {
		return $this->ownerUserId;
	}

	public function getOwnerLogin(): string {
		return $this->ownerLogin;
	}

	public function getOwnerName(): string {
		return $this->ownerName;
	}

	public function getNotificationAddress(): string {
		return $this->notificationAddress;
	}

	public function getNotificationLanguage(): string {
		return $this->notificationLanguage;
	}

	public function getLabel(): string {
		return $this->label;
	}

	public function getSecretHash(): string {
		return $this->secretHash;
	}

	public function isHmacEnabled(): bool {
		return $this->hmacEnabled;
	}

	public function getSecretCiphertext(): ?string {
		return $this->secretCiphertext;
	}

	public function getSecretCipherNonce(): ?string {
		return $this->secretCipherNonce;
	}

	public function getCreatedAt(): int {
		return $this->createdAt;
	}

	public function getExpiresAt(): ?int {
		return $this->expiresAt;
	}

	public function getRevokedAt(): ?int {
		return $this->revokedAt;
	}

	public function getWarningNotifiedAt(): ?int {
		return $this->warningNotifiedAt;
	}

	public function getExpiryNotifiedAt(): ?int {
		return $this->expiryNotifiedAt;
	}

	/**
	 * @return array<int,string>
	 */
	public function getServiceIds(): array {
		return $this->serviceIds;
	}

	public function hasService(string $serviceId): bool {
		return in_array($serviceId, $this->serviceIds, true);
	}

	public function isRevoked(): bool {
		return $this->revokedAt !== null;
	}

	public function isExpired(int $now): bool {
		return $this->expiresAt !== null && $this->expiresAt <= $now;
	}

	/**
	 * @param array<int,string> $serviceIds
	 */
	public function withManagementData(
		string $label,
		string $notificationAddress,
		string $notificationLanguage,
		?int $expiresAt,
		array $serviceIds,
		?array $operationGrants = null,
		?array $resourceConstraints = null
	): self {
		$expiryChanged = $expiresAt !== $this->expiresAt;

		return new self(
			$this->id,
			$this->publicId,
			$this->ownerUserId,
			$this->ownerLogin,
			$this->ownerName,
			$notificationAddress,
			$notificationLanguage,
			$label,
			$this->secretHash,
			$this->hmacEnabled,
			$this->secretCiphertext,
			$this->secretCipherNonce,
			$this->createdAt,
			$expiresAt,
			$this->revokedAt,
			$expiryChanged ? null : $this->warningNotifiedAt,
			$expiryChanged ? null : $this->expiryNotifiedAt,
			$serviceIds,
			$this->subject,
			$operationGrants ?? array_intersect_key($this->operationGrants, array_flip($serviceIds)),
		$resourceConstraints ?? array_intersect_key($this->resourceConstraints, array_flip($serviceIds))
		);
	}

	public function withRotatedSecret(
		string $publicId,
		string $secretHash,
		?string $secretCiphertext = null,
		?string $secretCipherNonce = null
	): self {
		return new self(
			$this->id,
			$publicId,
			$this->ownerUserId,
			$this->ownerLogin,
			$this->ownerName,
			$this->notificationAddress,
			$this->notificationLanguage,
			$this->label,
			$secretHash,
			$this->hmacEnabled,
			$this->hmacEnabled ? $secretCiphertext : null,
			$this->hmacEnabled ? $secretCipherNonce : null,
			$this->createdAt,
			$this->expiresAt,
			$this->revokedAt,
			$this->warningNotifiedAt,
			$this->expiryNotifiedAt,
			$this->serviceIds,
			$this->subject,
			$this->operationGrants,
			$this->resourceConstraints
		);
	}

	public function withRevokedAt(int $revokedAt): self {
		return new self(
			$this->id,
			$this->publicId,
			$this->ownerUserId,
			$this->ownerLogin,
			$this->ownerName,
			$this->notificationAddress,
			$this->notificationLanguage,
			$this->label,
			$this->secretHash,
			$this->hmacEnabled,
			$this->secretCiphertext,
			$this->secretCipherNonce,
			$this->createdAt,
			$this->expiresAt,
			$revokedAt,
			$this->warningNotifiedAt,
			$this->expiryNotifiedAt,
			$this->serviceIds,
			$this->subject,
			$this->operationGrants,
			$this->resourceConstraints
		);
	}
}
