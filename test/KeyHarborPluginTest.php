<?php declare(strict_types=1);

namespace KeyHarbor\Test;

use Base3\Api\IContainer;
use CredentialFoundation\Api\IApiCredentialService;
use CredentialFoundation\Api\ICredentialAccess;
use CredentialFoundation\Api\ICredentialOperationAccess;
use CredentialFoundation\Api\IEncryptionProfileService;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\KeyHarborPlugin;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use CredentialFoundation\Security\HmacRequestSigner;
use KeyHarbor\Service\CredentialManagementService;
use KeyHarbor\Service\CredentialServiceCatalog;
use KeyHarbor\Service\EncryptionProfileService;
use PHPUnit\Framework\TestCase;

final class KeyHarborPluginTest extends TestCase {

	public function testRegistersServicesAndReusesExistingCredentialAccessAliases(): void {
		$registrations = [];
		$container = $this->createMock(IContainer::class);
		$container->expects($this->exactly(12))
			->method('set')
			->willReturnCallback(static function(string $name, mixed $definition, int $flags) use (&$registrations, &$container): IContainer {
				$registrations[$name] = [$definition, $flags];
				return $container;
			});
		$plugin = new KeyHarborPlugin($container);

		self::assertSame('keyharborplugin', KeyHarborPlugin::getName());
		$plugin->init();

		self::assertSame($plugin, $registrations[KeyHarborPlugin::getName()][0]);
		self::assertSame(IContainer::SHARED, $registrations[KeyHarborPlugin::getName()][1]);
		foreach ([CredentialTokenService::class, CredentialSecretCipher::class, HmacRequestSigner::class, ICredentialRepository::class, CredentialServiceCatalog::class, IApiCredentialService::class, EncryptionProfileService::class, CredentialManagementService::class] as $service) {
			self::assertArrayHasKey($service, $registrations);
			self::assertInstanceOf(\Closure::class, $registrations[$service][0]);
			self::assertSame(IContainer::SHARED | IContainer::NOOVERWRITE, $registrations[$service][1]);
		}
		foreach ([
			ICredentialAccess::class => IApiCredentialService::class,
			ICredentialOperationAccess::class => IApiCredentialService::class,
			IEncryptionProfileService::class => EncryptionProfileService::class
		] as $alias => $target) {
			self::assertSame([$target, IContainer::ALIAS], $registrations[$alias]);
		}
	}

	public function testStatelessFactoryDefinitionsConstructSharedDependencies(): void {
		$registrations = [];
		$container = $this->createStub(IContainer::class);
		$container->method('set')->willReturnCallback(static function(string $name, mixed $definition, int $flags) use (&$registrations, &$container): IContainer {
			$registrations[$name] = $definition;
			return $container;
		});
		(new KeyHarborPlugin($container))->init();

		self::assertInstanceOf(CredentialTokenService::class, $registrations[CredentialTokenService::class]());
		self::assertInstanceOf(HmacRequestSigner::class, $registrations[HmacRequestSigner::class]());
	}
}
