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

	public function testCreatingCredentialRejectsInvalidLabelsWithoutPersistence(): void {
		[$service, $repository] = $this->createService();
		foreach (['  ', str_repeat('a', 256), "line\nfeed"] as $label) {
			try {
				$service->createForCurrentUser($label, '', 'en', null, ['example:read']);
				self::fail('Expected invalid label to be rejected.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('Credential label', $exception->getMessage());
			}
		}
		self::assertSame([], $repository->listAll());
	}

	public function testCreatingCredentialRejectsUnsupportedAuthenticationModes(): void {
		[$service, $repository] = $this->createService();
		foreach (['none', 'bearer+hmac', 'service'] as $mode) {
			try {
				$service->createForCurrentUser('Label', '', 'en', null, ['example:read'], $mode);
				self::fail('Expected unsupported mode to be rejected.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('authentication mode', $exception->getMessage());
			}
		}
		self::assertSame([], $repository->listAll());
	}

	public function testCreatingCredentialRejectsInvalidLanguagesAndExpiredTimestamps(): void {
		[$service, $repository] = $this->createService();
		foreach (['en US', str_repeat('a', 25)] as $language) {
			try {
				$service->createForCurrentUser('Label', '', $language, null, ['example:read']);
				self::fail('Expected invalid language to be rejected.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('Notification language', $exception->getMessage());
			}
		}
		try {
			$service->createForCurrentUser('Label', '', 'en', time() - 60, ['example:read']);
			self::fail('Expected expired creation to be rejected.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('expiration must be in the future', $exception->getMessage());
		}
		self::assertSame([], $repository->listAll());
	}

	public function testCurrentUserCannotEditRevokedCredential(): void {
		[$service, $repository] = $this->createService();
		$credential = $this->credential(['example:read'])->withRevokedAt(time());
		$repository->insert($credential);

		try {
			$service->updateForCurrentUser($credential->getId(), 'Changed', '', 'en', null, ['example:read']);
			self::fail('Expected update after revocation to be rejected.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::REVOKED, $exception->getReason());
		}
		self::assertSame($credential, $repository->getById($credential->getId()));
	}

	public function testCurrentUserCannotRotateRevokedCredential(): void {
		[$service, $repository] = $this->createService();
		$credential = $this->credential(['example:read'])->withRevokedAt(time());
		$repository->insert($credential);

		try {
			$service->rotateForCurrentUser($credential->getId());
			self::fail('Expected rotation after revocation to be rejected.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::REVOKED, $exception->getReason());
		}
		self::assertSame($credential, $repository->getById($credential->getId()));
	}

	public function testAdministratorCannotDeleteActiveCredential(): void {
		[$service, $repository] = $this->createService(true);
		$credential = $this->credential(['example:read']);
		$repository->insert($credential);

		try {
			$service->deleteAsAdmin($credential->getId());
			self::fail('Expected active credential deletion to be rejected.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::NOT_REVOKED, $exception->getReason());
		}
		self::assertSame($credential, $repository->getById($credential->getId()));
	}

	public function testAdministratorCannotTreatUserCredentialAsTechnicalCredential(): void {
		[$service, $repository] = $this->createService(true);
		$credential = $this->credential(['example:read']);
		$repository->insert($credential);

		try {
			$service->rotateServiceAsAdmin($credential->getId());
			self::fail('Expected a user credential to be excluded from technical rotation.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::NOT_FOUND, $exception->getReason());
		}
		self::assertSame($credential, $repository->getById($credential->getId()));
	}

	public function testOwningAdministratorCannotUseUserWorkflowOnServiceCredential(): void {
		[$service, $repository] = $this->createService(true);
		$credential = $service->createForService('contourz', 'Service key', '', 'en', null, [
			'example:read' => ['getEntries']
		])->getCredential();

		try {
			$service->rotateForCurrentUser($credential->getId());
			self::fail('Expected service credential to be excluded from human credential workflow.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::NOT_FOUND, $exception->getReason());
		}
		self::assertSame($credential, $repository->getById($credential->getId()));
	}

	public function testTechnicalCredentialOperationsAreDeduplicatedWithoutGrantExpansion(): void {
		[$service] = $this->createService(true);
		$credential = $service->createForService('contourz', 'Service key', '', 'en', null, [
			'example:read' => ['getEntry', 'getEntries', 'getEntry']
		])->getCredential();

		self::assertCount(2, $credential->getOperationGrants()['example:read']);
		self::assertTrue($credential->hasOperation('example:read', 'getEntry'));
		self::assertTrue($credential->hasOperation('example:read', 'getEntries'));
		self::assertFalse($credential->hasOperation('example:read', 'deleteEntry'));
	}

	public function testTechnicalCredentialRejectsEmptyOperationGrantWithoutInsert(): void {
		[$service, $repository] = $this->createService(true);

		try {
			$service->createForService('contourz', 'Service key', '', 'en', null, [
				'example:read' => []
			]);
			self::fail('Expected an empty technical operation grant to be rejected.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('at least one operation', $exception->getMessage());
		}
		self::assertSame([], $repository->listAll());
	}

	public function testAdministratorCannotEditRevokedTechnicalCredential(): void {
		[$service, $repository] = $this->createService(true);
		$credential = $service->createForService('contourz', 'Service key', '', 'en', null, [
			'example:read' => ['getEntries']
		])->getCredential();
		$revoked = $service->revokeAsAdmin($credential->getId());

		try {
			$service->updateServiceAsAdmin($credential->getId(), 'Changed', '', 'en', null, [
				'example:read' => ['getEntry']
			]);
			self::fail('Expected revoked technical credential editing to be rejected.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::REVOKED, $exception->getReason());
		}
		self::assertSame($revoked, $repository->getById($credential->getId()));
	}

	public function testAdministratorCannotRotateRevokedTechnicalCredential(): void {
		[$service, $repository] = $this->createService(true);
		$credential = $service->createForService('contourz', 'Service key', '', 'en', null, [
			'example:read' => ['getEntries']
		])->getCredential();
		$revoked = $service->revokeAsAdmin($credential->getId());

		try {
			$service->rotateServiceAsAdmin($credential->getId());
			self::fail('Expected revoked technical credential rotation to be rejected.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::REVOKED, $exception->getReason());
		}
		self::assertSame($revoked, $repository->getById($credential->getId()));
	}

	public function testChangingExpirationResetsBothNotificationMarkersWithoutChangingTokenIdentity(): void {
		[$service, $repository] = $this->createService();
		$original = $this->credential(['example:read']);
		$repository->insert($original);

		$updated = $service->updateForCurrentUser(
			$original->getId(), 'Renewed key', '', '', time() + 7200, ['example:read']
		);

		self::assertSame($original->getId(), $updated->getId());
		self::assertSame($original->getPublicId(), $updated->getPublicId());
		self::assertSame($original->getSecretHash(), $updated->getSecretHash());
		self::assertNull($updated->getWarningNotifiedAt());
		self::assertNull($updated->getExpiryNotifiedAt());
		self::assertSame($updated, $repository->getById($original->getId()));
	}

	public function testKeepingExpirationRetainsBothNotificationMarkers(): void {
		[$service, $repository] = $this->createService();
		$original = $this->credential(['example:read']);
		$repository->insert($original);

		$updated = $service->updateForCurrentUser(
			$original->getId(), 'Renamed key', '', '', $original->getExpiresAt(), ['example:read']
		);

		self::assertSame($original->getWarningNotifiedAt(), $updated->getWarningNotifiedAt());
		self::assertSame($original->getExpiryNotifiedAt(), $updated->getExpiryNotifiedAt());
		self::assertSame('Renamed key', $updated->getLabel());
	}

	public function testRemovingAServiceFromAnOwnedCredentialRemovesTheGrant(): void {
		[$service, $repository] = $this->createService();
		$original = $this->credential(['example:read', 'example:write']);
		$repository->insert($original);

		$updated = $service->updateForCurrentUser(
			$original->getId(), 'Reduced grants', '', '', $original->getExpiresAt(), ['example:read']
		);

		self::assertSame(['example:read'], $updated->getServiceIds());
		self::assertTrue($updated->hasService('example:read'));
		self::assertFalse($updated->hasService('example:write'));
		self::assertSame($updated, $repository->getById($original->getId()));
	}

	public function testExpiredServiceCredentialCannotBeRotatedByAdministrator(): void {
		[$service, $repository] = $this->createService(true);
		$credential = $service->createForService(
			'worker', 'Worker', '', '', null, ['example:read' => ['getEntries']]
		)->getCredential();
		$expired = $credential->withManagementData(
			$credential->getLabel(), $credential->getNotificationAddress(),
			$credential->getNotificationLanguage(), time() - 60,
			$credential->getServiceIds(), $credential->getOperationGrants()
		);
		$repository->update($expired);

		try {
			$service->rotateServiceAsAdmin($expired->getId());
			self::fail('Expired technical credentials must not be rotated.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::EXPIRED, $exception->getReason());
		}
		self::assertSame($expired, $repository->getById($expired->getId()));
	}

	public function testAdministratorRevokingUnknownCredentialDoesNotCreateARecord(): void {
		[$service, $repository] = $this->createService(true);

		try {
			$service->revokeAsAdmin(str_repeat('f', 32));
			self::fail('Unknown credentials must not be revoked.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::NOT_FOUND, $exception->getReason());
		}
		self::assertSame([], $repository->listAll());
	}

	public function testAdministratorCannotUpdateAnUnknownServiceCredential(): void {
		[$service, $repository] = $this->createService(true);

		try {
			$service->updateServiceAsAdmin(
				str_repeat('f', 32), 'Missing', '', '', null, ['example:read' => ['getEntries']]
			);
			self::fail('Missing technical credentials must not be edited.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::NOT_FOUND, $exception->getReason());
		}
		self::assertSame([], $repository->listAll());
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
		$authorization = $this->createStub(IAuthorizationService::class);
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
