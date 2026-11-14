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

final class ManagementTestUsermanager implements IUsermanager {

	public function __construct(
		private readonly ?User $user,
		private readonly bool $admin
	) {}

	public function getUser() {
		return $this->user;
	}

	public function getUserById(int|string $id): ?User {
		return $this->user !== null && (string)$this->user->id === (string)$id ? $this->user : null;
	}

	public function getGroups() {
		return [];
	}

	public function getRoles() {
		return [];
	}

	public function getPermissions() {
		return [];
	}

	public function hasRole(Role $role): bool {
		return false;
	}

	public function registUser($userid, $password, $data = null) {
		return false;
	}

	public function changePassword($oldpassword, $newpassword) {
		return false;
	}

	public function getAllUsers() {
		return [];
	}

	public function searchUsers(string $query, int $limit = 20, int $offset = 0): array {
		return [];
	}

	public function getAllGroups() {
		return [];
	}

	public function getAllRoles() {
		return [];
	}

	public function getAllPermissions() {
		return [];
	}

	public function assignRoleToUser($userid, Role $role): bool {
		return false;
	}

	public function revokeRoleFromUser($userid, Role $role): bool {
		return false;
	}

	public function assignRoleToGroup($groupid, Role $role): bool {
		return false;
	}

	public function revokeRoleFromGroup($groupid, Role $role): bool {
		return false;
	}

	public function addPermissionToRole(Role $role, Permission $permission): bool {
		return false;
	}

	public function removePermissionFromRole(Role $role, Permission $permission): bool {
		return false;
	}
}
