<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Api\IClassMap;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3\Usermanager\Role;
use Base3\Usermanager\User;
use CredentialFoundation\Api\ICredentialServiceProvider;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use InvalidArgumentException;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Exception\CredentialManagementException;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Service\CredentialManagementService;
use KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;

final class ManagementTestRepository implements ICredentialRepository {

	/** @var array<string,ApiCredential> */
	private array $credentials = [];

	public function insert(ApiCredential $credential): void {
		$this->credentials[$credential->getId()] = $credential;
	}

	public function getById(string $id): ?ApiCredential {
		return $this->credentials[$id] ?? null;
	}

	public function getByPublicId(string $publicId): ?ApiCredential {
		foreach ($this->credentials as $credential) {
			if ($credential->getPublicId() === $publicId) {
				return $credential;
			}
		}
		return null;
	}

	public function getByOwner(string $id, int|string $ownerUserId): ?ApiCredential {
		$credential = $this->getById($id);
		if ($credential === null || ($credential->isServiceCredential() || $credential->getOwnerUserId() !== (string)$ownerUserId)) {
			return null;
		}
		return $credential;
	}

	public function listByOwner(int|string $ownerUserId): array {
		return array_values(array_filter(
			$this->credentials,
			fn(ApiCredential $credential): bool => !$credential->isServiceCredential() && $credential->getOwnerUserId() === (string)$ownerUserId
		));
	}

	public function listAll(): array {
		return array_values($this->credentials);
	}

	public function update(ApiCredential $credential): bool {
		if (!isset($this->credentials[$credential->getId()])) {
			return false;
		}
		$this->credentials[$credential->getId()] = $credential;
		return true;
	}

	public function updateForOwner(ApiCredential $credential, int|string $ownerUserId): bool {
		if ($this->getByOwner($credential->getId(), $ownerUserId) === null) {
			return false;
		}
		return $this->update($credential);
	}

	public function deleteRevoked(string $id): bool {
		$credential = $this->getById($id);
		if ($credential === null || !$credential->isRevoked()) {
			return false;
		}
		unset($this->credentials[$id]);
		return true;
	}

	public function deleteRevokedForOwner(string $id, int|string $ownerUserId): bool {
		$credential = $this->getByOwner($id, $ownerUserId);
		if ($credential === null || !$credential->isRevoked()) {
			return false;
		}
		unset($this->credentials[$id]);
		return true;
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
