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

final class CredentialManagementServiceTest extends TestCase {

	public function testUsermanagerFixtureSupportsSearchContract(): void {
		$user = $this->user();
		$usermanager = new ManagementTestUsermanager($user, false);

		self::assertSame([], $usermanager->searchUsers('demo'));
		self::assertSame($user, $usermanager->getUserById(42));
	}

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

	public function testAdminCreatesEditsAndRotatesTechnicalCredentialWithoutUserImpersonation(): void {
		[$service, $repository] = $this->createService(true);
		$issued = $service->createForService('contourz', 'Contourz', 'admin@example.test', 'en', null, ['example:read' => ['getEntries']]);
		$credential = $issued->getCredential();
		self::assertSame('service', $credential->getSubject()->getType());
		self::assertSame('contourz', $credential->getSubject()->getId());
		self::assertSame('42', $credential->getOwnerUserId());
		self::assertTrue($credential->isHmacEnabled());
		self::assertTrue($credential->hasOperation('example:read', 'getEntries'));
		self::assertFalse($credential->hasOperation('example:read', 'getEntry'));
		self::assertCount(1, $service->listAllForAdmin());
		self::assertSame([], $service->listForCurrentUser());
		$updated = $service->updateServiceAsAdmin($credential->getId(), 'Contourz production', 'admin@example.test', 'de', null, ['example:read' => ['getEntry']]);
		self::assertFalse($updated->hasOperation('example:read', 'getEntries'));
		self::assertTrue($updated->hasOperation('example:read', 'getEntry'));
		$rotated = $service->rotateServiceAsAdmin($credential->getId());
		self::assertNotSame($issued->getGeneratedToken()->getToken(), $rotated->getGeneratedToken()->getToken());
		self::assertSame('contourz', $rotated->getCredential()->getSubject()->getId());
		self::assertFalse($rotated->getCredential()->hasOperation('example:read', 'getEntries'));
		self::assertTrue($service->revokeAsAdmin($credential->getId())->isRevoked());
		$service->deleteAsAdmin($credential->getId());
		self::assertNull($repository->getById($credential->getId()));
	}

	public function testNonAdminCannotCreateTechnicalCredential(): void {
		[$service] = $this->createService();
		$this->expectException(CredentialManagementException::class);
		$service->createForService('contourz', 'Contourz', '', 'en', null, ['example:read' => ['getEntries']]);
	}

	public function testAdminRejectsUndeclaredServiceOperation(): void {
		[$service] = $this->createService(true);
		$this->expectException(InvalidArgumentException::class);
		$service->createForService('contourz', 'Contourz', '', 'en', null, ['example:read' => ['deleteEntry']]);
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
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('ilias');
		$authorization->method('decide')->willReturn($admin ? AuthorizationDecision::allow() : AuthorizationDecision::deny());
		$service = new CredentialManagementService(
			new ManagementTestUsermanager($user ?? $this->user(), $admin),
			$authorization,
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

require_once __DIR__ . '/ManagementTestProvider.php';

require_once __DIR__ . '/ManagementTestRepository.php';

require_once __DIR__ . '/ManagementTestUsermanager.php';

require_once __DIR__ . '/ManagementTestClassMap.php';

require_once __DIR__ . '/ManagementTestConfiguration.php';

require_once __DIR__ . '/ManagementTestConfigValueResolver.php';
