<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Api\IClassMap;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\State\Api\IStateStore;
use CredentialFoundation\Api\ICredentialServiceProvider;
use CredentialFoundation\Dto\CredentialAuthenticationResult;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use CredentialFoundation\Dto\HmacAuthenticationRequest;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Security\HmacRequestSigner;
use KeyHarbor\Service\ApiCredentialService;
use KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;

final class AuthenticationTestRepository implements ICredentialRepository {

	/** @var array<string,ApiCredential> */
	private array $credentialsByPublicId = [];

	/** @param array<int,ApiCredential> $credentials */
	public function __construct(array $credentials) {
		foreach ($credentials as $credential) {
			$this->credentialsByPublicId[$credential->getPublicId()] = $credential;
		}
	}

	public function insert(ApiCredential $credential): void {
		$this->credentialsByPublicId[$credential->getPublicId()] = $credential;
	}

	public function getById(string $id): ?ApiCredential {
		foreach ($this->credentialsByPublicId as $credential) {
			if ($credential->getId() === $id) {
				return $credential;
			}
		}
		return null;
	}

	public function getByPublicId(string $publicId): ?ApiCredential {
		return $this->credentialsByPublicId[$publicId] ?? null;
	}

	public function getByOwner(string $id, int|string $ownerUserId): ?ApiCredential {
		$credential = $this->getById($id);
		if ($credential === null || $credential->getOwnerUserId() !== (string)$ownerUserId) {
			return null;
		}
		return $credential;
	}

	public function listByOwner(int|string $ownerUserId): array {
		return array_values(array_filter(
			$this->credentialsByPublicId,
			fn(ApiCredential $credential): bool => $credential->getOwnerUserId() === (string)$ownerUserId
		));
	}

	public function listAll(): array {
		return array_values($this->credentialsByPublicId);
	}

	public function update(ApiCredential $credential): bool {
		if ($this->getById($credential->getId()) === null) {
			return false;
		}
		$this->credentialsByPublicId[$credential->getPublicId()] = $credential;
		return true;
	}

	public function updateForOwner(ApiCredential $credential, int|string $ownerUserId): bool {
		if ($this->getByOwner($credential->getId(), $ownerUserId) === null) {
			return false;
		}
		return $this->update($credential);
	}

	public function deleteRevoked(string $id): bool {
		return false;
	}

	public function deleteRevokedForOwner(string $id, int|string $ownerUserId): bool {
		return false;
	}

	public function findExpiring(int $fromExclusive, int $toInclusive): array {
		return [];
	}

	public function findExpired(int $now): array {
		return [];
	}

	public function markWarningNotified(string $id, int $notifiedAt): bool {
		return $this->getById($id) !== null;
	}

	public function markExpiryNotified(string $id, int $notifiedAt): bool {
		return $this->getById($id) !== null;
	}
}
