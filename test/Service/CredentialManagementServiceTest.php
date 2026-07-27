<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Api\IClassMap;
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

final class CredentialManagementServiceTest extends TestCase {

	public function testCreatesHmacCredentialWithEncryptedSecretAndRotatesIt(): void {
		[$service] = $this->createService();

		$issued = $service->createForCurrentUser(
			'HMAC client',
			'',
			'',
			time() + 3600,
			['example:read'],
			'hmac'
		);

		$credential = $issued->getCredential();
		self::assertTrue($credential->isHmacEnabled());
		self::assertNotEmpty($credential->getSecretCiphertext());
		self::assertNotEmpty($credential->getSecretCipherNonce());

		$rotated = $service->rotateForCurrentUser($credential->getId());
		self::assertTrue($rotated->getCredential()->isHmacEnabled());
		self::assertNotSame(
			$credential->getSecretCiphertext(),
			$rotated->getCredential()->getSecretCiphertext()
		);
	}

	public function testCreatesOwnerBoundBearerCredentialAndReturnsTokenOnce(): void {
		[$service, $repository, $tokenService] = $this->createService();

		$issued = $service->createForCurrentUser(
			'Report client',
			'',
			'',
			time() + 3600,
			['example:write', 'example:read']
		);

		$credential = $issued->getCredential();
		self::assertSame('42', $credential->getOwnerUserId());
		self::assertSame('demo', $credential->getOwnerLogin());
		self::assertSame('demo@example.test', $credential->getNotificationAddress());
		self::assertSame('en', $credential->getNotificationLanguage());
		self::assertSame(['example:read', 'example:write'], $credential->getServiceIds());
		self::assertFalse($credential->isHmacEnabled());
		self::assertSame($credential, $repository->getById($credential->getId()));

		$parsed = $tokenService->parse($issued->getGeneratedToken()->getToken());
		self::assertNotNull($parsed);
		self::assertSame($credential->getPublicId(), $parsed->getPublicId());
		self::assertTrue($tokenService->verifySecret($parsed->getSecret(), $credential->getSecretHash()));
	}

	public function testRequiresNotificationAddressForExpiringCredential(): void {
		$user = $this->user();
		$user->email = '';
		[$service] = $this->createService(false, $user);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('notification address');
		$service->createForCurrentUser('Expiring', '', 'en', time() + 3600, ['example:read']);
	}

	public function testRejectsUnknownGrantOnCreation(): void {
		[$service] = $this->createService();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown credential service');
		$service->createForCurrentUser('Invalid', '', '', null, ['missing:service']);
	}

	public function testUpdatesOwnedCredentialAndPreservesExistingUnavailableGrant(): void {
		[$service, $repository] = $this->createService();
		$credential = $this->credential(['example:read', 'removed:service']);
		$repository->insert($credential);

		$updated = $service->updateForCurrentUser(
			$credential->getId(),
			'Updated label',
			'notify@example.test',
			'de',
			time() + 7200,
			['example:write', 'removed:service']
		);

		self::assertSame('Updated label', $updated->getLabel());
		self::assertSame(['example:write', 'removed:service'], $updated->getServiceIds());
		self::assertNull($updated->getWarningNotifiedAt());
		self::assertNull($updated->getExpiryNotifiedAt());
	}

	public function testCannotUpdateAnotherUsersCredential(): void {
		[$service, $repository] = $this->createService();
		$repository->insert($this->credential(['example:read'], '99'));

		$this->expectException(CredentialManagementException::class);
		$this->expectExceptionMessage('not found');
		$service->updateForCurrentUser(
			str_repeat('a', 32),
			'No access',
			'',
			'en',
			null,
			['example:read']
		);
	}

	public function testRotatesSecretWithoutChangingCredentialIdentity(): void {
		[$service, $repository, $tokenService] = $this->createService();
		$credential = $this->credential(['example:read']);
		$repository->insert($credential);

		$issued = $service->rotateForCurrentUser($credential->getId());
		$rotated = $issued->getCredential();

		self::assertSame($credential->getId(), $rotated->getId());
		self::assertNotSame($credential->getPublicId(), $rotated->getPublicId());
		self::assertNotSame($credential->getSecretHash(), $rotated->getSecretHash());
		$parsed = $tokenService->parse($issued->getGeneratedToken()->getToken());
		self::assertNotNull($parsed);
		self::assertTrue($tokenService->verifySecret($parsed->getSecret(), $rotated->getSecretHash()));
	}

	public function testRejectsRotationOfExpiredCredential(): void {
		[$service, $repository] = $this->createService();
		$credential = new ApiCredential(
			str_repeat('a', 32),
			str_repeat('b', 20),
			'42',
			'demo',
			'Demo User',
			'demo@example.test',
			'en',
			'Expired credential',
			str_repeat('c', 64),
			false,
			null,
			null,
			time() - 7200,
			time() - 3600,
			null,
			null,
			null,
			['example:read']
		);
		$repository->insert($credential);

		$this->expectException(CredentialManagementException::class);
		$this->expectExceptionMessage('Extend the expiration');
		$service->rotateForCurrentUser($credential->getId());
	}

	public function testRevocationIsOwnerBoundAndIdempotent(): void {
		[$service, $repository] = $this->createService();
		$credential = $this->credential(['example:read']);
		$repository->insert($credential);

		$revoked = $service->revokeForCurrentUser($credential->getId());
		$again = $service->revokeForCurrentUser($credential->getId());

		self::assertTrue($revoked->isRevoked());
		self::assertSame($revoked->getRevokedAt(), $again->getRevokedAt());
	}

	public function testCurrentUserCanDeleteOnlyOwnedRevokedCredential(): void {
		[$service, $repository] = $this->createService();
		$credential = $this->credential(['example:read']);
		$repository->insert($credential);

		try {
			$service->deleteForCurrentUser($credential->getId());
			self::fail('Expected active credential deletion to be rejected.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::NOT_REVOKED, $exception->getReason());
		}

		$service->revokeForCurrentUser($credential->getId());
		$service->deleteForCurrentUser($credential->getId());

		self::assertNull($repository->getById($credential->getId()));
	}

	public function testCurrentUserCannotDeleteAnotherUsersRevokedCredential(): void {
		[$service, $repository] = $this->createService();
		$credential = $this->credential(['example:read'], '99')->withRevokedAt(time());
		$repository->insert($credential);

		$this->expectException(CredentialManagementException::class);
		$this->expectExceptionMessage('not found');
		$service->deleteForCurrentUser($credential->getId());
	}

	public function testAdminCanDeleteRevokedCredential(): void {
		[$service, $repository] = $this->createService(true);
		$credential = $this->credential(['example:read'], '99')->withRevokedAt(time());
		$repository->insert($credential);

		$service->deleteAsAdmin($credential->getId());

		self::assertNull($repository->getById($credential->getId()));
	}

	public function testAdminOverviewAndRevocationRequireSystemAdminPermission(): void {
		[$deniedService, $repository] = $this->createService(false);
		$repository->insert($this->credential(['example:read']));

		try {
			$deniedService->listAllForAdmin();
			self::fail('Expected admin access to be denied.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::ACCESS_DENIED, $exception->getReason());
		}

		[$adminService, $adminRepository] = $this->createService(true);
		$credential = $this->credential(['example:read'], '99');
		$adminRepository->insert($credential);
		self::assertCount(1, $adminService->listAllForAdmin());
		self::assertTrue($adminService->revokeAsAdmin($credential->getId())->isRevoked());
	}

	/**
	 * @return array{0:CredentialManagementService,1:ManagementTestRepository,2:CredentialTokenService}
	 */
	private function createService(bool $admin = false, ?User $user = null): array {
		$repository = new ManagementTestRepository();
		$tokenService = new CredentialTokenService();
		$catalog = new CredentialServiceCatalog(new ManagementTestClassMap([
			new ManagementTestProvider()
		]));
		$service = new CredentialManagementService(
			new ManagementTestUsermanager($user ?? $this->user(), $admin),
			$repository,
			$tokenService,
			$catalog,
			new CredentialSecretCipher(
				new ManagementTestConfiguration(),
				new ManagementTestConfigValueResolver()
			)
		);
		return [$service, $repository, $tokenService];
	}

	private function user(): User {
		$user = new User();
		$user->id = 42;
		$user->userid = 'demo';
		$user->name = 'Demo User';
		$user->email = 'demo@example.test';
		$user->lang = 'en';
		return $user;
	}

	/** @param array<int,string> $serviceIds */
	private function credential(array $serviceIds, string $ownerUserId = '42'): ApiCredential {
		return new ApiCredential(
			str_repeat('a', 32),
			str_repeat('b', 20),
			$ownerUserId,
			'owner-' . $ownerUserId,
			'Owner ' . $ownerUserId,
			'owner@example.test',
			'en',
			'Existing credential',
			str_repeat('c', 64),
			false,
			null,
			null,
			time() - 100,
			time() + 3600,
			null,
			time() - 50,
			time() - 25,
			$serviceIds
		);
	}
}

final class ManagementTestProvider implements ICredentialServiceProvider {

	public static function getName(): string {
		return 'managementtestprovider';
	}

	public function getServices(): array {
		return [
			new CredentialServiceDefinition('example:read', 'Example read'),
			new CredentialServiceDefinition('example:write', 'Example write')
		];
	}
}

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
		if ($credential === null || $credential->getOwnerUserId() !== (string)$ownerUserId) {
			return null;
		}
		return $credential;
	}

	public function listByOwner(int|string $ownerUserId): array {
		return array_values(array_filter(
			$this->credentials,
			fn(ApiCredential $credential): bool => $credential->getOwnerUserId() === (string)$ownerUserId
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

	public function can(Permission $permission): bool {
		return $this->admin && $permission->scope === 'system' && $permission->permission === 'admin';
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

final class ManagementTestClassMap implements IClassMap {

	/** @var array<int,object> */
	private array $instances;
	/** @var array<int,object> */
	private array $empty = [];

	/** @param array<int,object> $instances */
	public function __construct(array $instances) {
		$this->instances = $instances;
	}

	public function instantiate(string $class) {
		return null;
	}

	public function instantiateWith(string $class, array $arguments = []) {
		return null;
	}

	public function generate($regenerate = false): void {}

	public function getApps() {
		return [];
	}

	public function &getInstances(array $criteria = []) {
		return $this->empty;
	}

	public function &getInstancesByInterface($interface) {
		return $this->instances;
	}

	public function &getInstancesByAppInterface($app, $interface, $retry = false) {
		return $this->empty;
	}

	public function &getInstanceByAppName($app, $name, $retry = false) {
		$result = null;
		return $result;
	}

	public function getClassByInterfaceName(string $interface, string $name): ?string {
		return null;
	}

	public function &getInstanceByInterfaceName($interface, $name, $retry = false) {
		$result = null;
		return $result;
	}

	public function &getInstanceByAppInterfaceName($app, $interface, $name, $retry = false) {
		$result = null;
		return $result;
	}

	public function getPlugins() {
		return [];
	}
}

final class ManagementTestConfiguration implements IConfiguration {

	private string $keyDefinition = '';

	public function __construct() {
		$this->keyDefinition = base64_encode(str_repeat('k', 32));
	}

	public function get($configuration = '') { return []; }
	public function set($data, $configuration = ''): void {}
	public function save(): void {}
	public function getGroup(string $group, array $default = []): array { return $default; }
	public function getValue(string $group, string $key, $default = null) {
		return $group === 'keyharbor' && $key === 'hmac_master_key' ? $this->keyDefinition : $default;
	}
	public function getString(string $group, string $key, string $default = ''): string { return $default; }
	public function getInt(string $group, string $key, int $default = 0): int { return $default; }
	public function getFloat(string $group, string $key, float $default = 0.0): float { return $default; }
	public function getBool(string $group, string $key, bool $default = false): bool { return $default; }
	public function getArray(string $group, string $key, array $default = []): array { return $default; }
	public function hasGroup(string $group): bool { return $group === 'keyharbor'; }
	public function hasValue(string $group, string $key): bool { return $group === 'keyharbor' && $key === 'hmac_master_key'; }
	public function setValue(string $group, string $key, $value): void {}
	public function setGroup(string $group, array $values, bool $merge = true): void {}
	public function setMany(array $data, bool $merge = true): void {}
	public function removeGroup(string $group): void {}
	public function removeValue(string $group, string $key): void {}
	public function isDirty(): bool { return false; }
	public function saveIfDirty(): bool { return true; }
	public function trySave(): bool { return true; }
	public function reload(): void {}
	public function persistValue(string $group, string $key, $value): bool { return true; }
}

final class ManagementTestConfigValueResolver implements IConfigValueResolver {

	public static function getName(): string { return 'managementtestconfigvalueresolver'; }
	public function resolve(array|string|int|float|bool|null $config): mixed {
		return base64_encode(str_repeat('k', 32));
	}
	public function getModes(): array { return []; }
	public function getModeSchema(string $mode): ?array { return null; }
	public function getModeSchemas(): array { return []; }
	public function getModeResolverNames(): array { return []; }
}
