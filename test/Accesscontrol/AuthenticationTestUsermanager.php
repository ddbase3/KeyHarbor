<?php declare(strict_types=1);

namespace KeyHarbor\Test\Accesscontrol;

use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\Permission;
use Base3\Usermanager\Role;
use Base3\Usermanager\User;

/** Keeps native ids and host logins separate in KeyHarbor authentication tests. */
final class AuthenticationTestUsermanager implements IUsermanager {

	/** @param array<string,string> $users Native user id => exact host login. */
	public function __construct(private readonly array $users = ['42' => 'user_42', '28038' => 'user_28038']) {}

	public function getUser() { return null; }

	public function getUserById(int|string $id): ?User {
		$login = $this->users[(string)$id] ?? null;
		return $login !== null ? User::fromArray(['id' => $id, 'userid' => $login]) : null;
	}

	public function getGroups() { return []; }
	public function getRoles() { return []; }
	public function getPermissions() { return []; }
	public function hasRole(Role $role): bool { return false; }
	public function registUser($userid, $password, $data = null) { return false; }
	public function changePassword($oldpassword, $newpassword) { return false; }
	public function getAllUsers() { return []; }
	public function searchUsers(string $query, int $limit = 20, int $offset = 0): array { return []; }
	public function getAllGroups() { return []; }
	public function getAllRoles() { return []; }
	public function getAllPermissions() { return []; }
	public function assignRoleToUser($userid, Role $role): bool { return false; }
	public function revokeRoleFromUser($userid, Role $role): bool { return false; }
	public function assignRoleToGroup($groupid, Role $role): bool { return false; }
	public function revokeRoleFromGroup($groupid, Role $role): bool { return false; }
	public function addPermissionToRole(Role $role, Permission $permission): bool { return false; }
	public function removePermissionFromRole(Role $role, Permission $permission): bool { return false; }
}
