<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Usermanager\User;
use InvalidArgumentException;
use KeyHarbor\Exception\CredentialManagementException;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Service\CredentialManagementService;
use KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;

final class CredentialManagementInputBoundariesTest extends TestCase {

	public function testMissingUserFailsClosedForCurrentUserAndAdminChecks(): void {
		[$service, $repository] = $this->service(null, true);
		self::assertFalse($service->isCurrentUserAdmin());
		foreach (['getCurrentUser', 'assertCurrentUserAdmin', 'listForCurrentUser', 'listAllForAdmin'] as $method) {
			try {
				$service->$method();
				self::fail('Unauthenticated actor was allowed: ' . $method);
			} catch (CredentialManagementException $exception) {
				self::assertSame(CredentialManagementException::NOT_AUTHENTICATED, $exception->getReason());
			}
		}
		self::assertSame([], $repository->listAll());
	}

	public function testDeniedAdministratorCannotListAllCredentials(): void {
		[$service] = $this->service($this->user(), false);
		self::assertFalse($service->isCurrentUserAdmin());
		try {
			$service->listAllForAdmin();
			self::fail('Only authorized administrators may list credentials globally.');
		} catch (CredentialManagementException $exception) {
			self::assertSame(CredentialManagementException::ACCESS_DENIED, $exception->getReason());
		}
	}

	public function testCatalogDiscoveryAndUserListingDoNotRequireAdministratorPrivileges(): void {
		[$service, $repository] = $this->service($this->user(), false);
		self::assertCount(2, $service->getAvailableServices());
		self::assertSame('42', (string)$service->getCurrentUser()->id);
		$issued = $service->createForCurrentUser('Own key', '', '', null, ['example:read']);
		self::assertSame([$issued->getCredential()], $service->listForCurrentUser());
		self::assertSame([$issued->getCredential()], $repository->listAll());
	}

	public function testAdministratorListingIncludesBothUserAndServiceCredentials(): void {
		[$service] = $this->service($this->user(), true);
		$user = $service->createForCurrentUser('User key', '', '', null, ['example:read'])->getCredential();
		$technical = $service->createForService('service-one', 'Service key', '', '', null, ['example:read' => ['getEntries']])->getCredential();
		self::assertTrue($service->isCurrentUserAdmin());
		self::assertSame([$user, $technical], $service->listAllForAdmin());
		self::assertSame([$user], $service->listForCurrentUser());
	}

	public function testMissingProfileLoginAndNameFallBackToVerifiedUserId(): void {
		$user = $this->user();
		$user->userid = ' ';
		$user->name = ' ';
		$user->lang = '';
		[$service] = $this->service($user, false);
		$credential = $service->createForCurrentUser('Fallback key', '', '', null, ['example:read'])->getCredential();
		self::assertSame('42', $credential->getOwnerLogin());
		self::assertSame('42', $credential->getOwnerName());
		self::assertSame('en', $credential->getNotificationLanguage());
	}

	public function testOptionalEmailIsAllowedWithoutExpiryButRequiredWithExpiry(): void {
		$user = $this->user();
		$user->email = '';
		[$service, $repository] = $this->service($user, false);
		$credential = $service->createForCurrentUser('No expiry', '', '', null, ['example:read'])->getCredential();
		self::assertSame('', $credential->getNotificationAddress());
		try {
			$service->createForCurrentUser('Expiring', '', '', time() + 3600, ['example:read']);
			self::fail('Expiring credentials require a notification address.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('notification address', $exception->getMessage());
		}
		self::assertCount(1, $repository->listAll());
	}

	public function testDuplicateAndWhitespaceServiceIdsAreNormalizedBeforePersisting(): void {
		[$service] = $this->service($this->user(), false);
		$credential = $service->createForCurrentUser(' Trimmed ', '', '', null, [
			' example:write ', '', 'example:read', 'example:read', ' '
		])->getCredential();
		self::assertSame('Trimmed', $credential->getLabel());
		self::assertSame(['example:read', 'example:write'], $credential->getServiceIds());
	}

	public function testNonScalarOrEmptyServiceListsFailBeforeInsertion(): void {
		[$service, $repository] = $this->service($this->user(), false);
		foreach ([[['example:read']], [null], ['', '  ']] as $services) {
			try {
				$service->createForCurrentUser('API key', '', '', null, $services);
				self::fail('Invalid service ids were accepted.');
			} catch (InvalidArgumentException $exception) {
				self::assertNotSame('', $exception->getMessage());
			}
		}
		self::assertSame([], $repository->listAll());
	}

	public function testLabelAndNotificationAddressRejectControlCharactersAndOversizedValues(): void {
		[$service, $repository] = $this->service($this->user(), false);
		foreach (["bad\nlabel", str_repeat('l', 256)] as $label) {
			try {
				$service->createForCurrentUser($label, '', '', null, ['example:read']);
				self::fail('Invalid credential label was accepted.');
			} catch (InvalidArgumentException $exception) {
				self::assertNotSame('', $exception->getMessage());
			}
		}
		foreach (["contact\r@example.invalid", str_repeat('a', 513)] as $address) {
			try {
				$service->createForCurrentUser('API key', $address, '', null, ['example:read']);
				self::fail('Invalid notification address was accepted.');
			} catch (InvalidArgumentException $exception) {
				self::assertNotSame('', $exception->getMessage());
			}
		}
		self::assertSame([], $repository->listAll());
	}

	public function testMalformedCredentialIdentifierIsRejectedBeforeAnyUpdate(): void {
		[$service, $repository] = $this->service($this->user(), false);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Credential ids must contain 32 lowercase hexadecimal characters.');
		$service->updateForCurrentUser('UPPER_OR_INVALID', 'Changed', '', '', null, ['example:read']);
	}

	public function testTechnicalResourceConstraintsRequireTheServiceToDeclareAttributes(): void {
		[$service, $repository] = $this->service($this->user(), true);
		try {
			$service->createForService('service-one', 'Technical', '', '', null, ['example:read' => ['getEntries']], [
				'example:read' => ['getEntries' => [
					'version' => 1,
					'expression' => ['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq', 'value' => '42']
				]]
			]);
			self::fail('Undeclared service resource attributes must not be granted.');
		} catch (InvalidArgumentException $exception) {
			self::assertNotSame('', $exception->getMessage());
		}
		self::assertSame([], $repository->listAll());
	}

	/** @return array{CredentialManagementService,ManagementTestRepository} */
	private function service(?User $user, bool $admin): array {
		$repository = new ManagementTestRepository();
		$authorization = $this->createStub(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('ilias');
		$authorization->method('decide')->willReturn($admin ? AuthorizationDecision::allow() : AuthorizationDecision::deny());
		$service = new CredentialManagementService(
			new ManagementTestUsermanager($user, $admin),
			$authorization,
			$repository,
			new CredentialTokenService(),
			new CredentialServiceCatalog(new ManagementTestClassMap([new ManagementTestProvider()])),
			new CredentialSecretCipher(new ManagementTestConfiguration(), new ManagementTestConfigValueResolver())
		);
		return [$service, $repository];
	}

	private function user(): User {
		$user = new User();
		$user->id = 42;
		$user->userid = 'demo';
		$user->name = 'Demo User';
		$user->email = 'demo@example.invalid';
		$user->lang = 'en';
		return $user;
	}
}

require_once __DIR__ . '/ManagementTestUsermanager.php';
require_once __DIR__ . '/ManagementTestRepository.php';
require_once __DIR__ . '/ManagementTestClassMap.php';
require_once __DIR__ . '/ManagementTestProvider.php';
require_once __DIR__ . '/ManagementTestConfiguration.php';
require_once __DIR__ . '/ManagementTestConfigValueResolver.php';
