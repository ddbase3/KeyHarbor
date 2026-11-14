<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of KeyHarbor for BASE3 Framework.
 *
 * KeyHarbor manages durable API credentials for BASE3 applications.
 * It provides secure credential lifecycle and encryption profiles.
 * Authorization and credential subjects follow existing shared contracts.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 * https://github.com/ddbase3/KeyHarbor
 **********************************************************************/

namespace Base3\KeyHarbor\Test\Service;

use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationDecision;
use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\ConfigValue\Api\IConfigValueResolver;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CoreFoundation\Usermanager\Permission;
use Base3\CoreFoundation\Usermanager\Role;
use Base3\CoreFoundation\Usermanager\User;
use Base3\CredentialFoundation\Api\ICredentialServiceProvider;
use Base3\CredentialFoundation\Dto\CredentialServiceDefinition;
use InvalidArgumentException;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\Exception\CredentialManagementException;
use Base3\KeyHarbor\Model\ApiCredential;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
use Base3\KeyHarbor\Security\CredentialTokenService;
use Base3\KeyHarbor\Service\CredentialManagementService;
use Base3\KeyHarbor\Service\CredentialServiceCatalog;
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
