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

namespace Base3\KeyHarbor\Test;

use Base3\CoreFoundation\Api\IContainer;
use Base3\CredentialFoundation\Api\IApiCredentialService;
use Base3\CredentialFoundation\Api\ICredentialAccess;
use Base3\CredentialFoundation\Api\ICredentialOperationAccess;
use Base3\CredentialFoundation\Api\IEncryptionProfileService;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\KeyHarborPlugin;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
use Base3\KeyHarbor\Security\CredentialTokenService;
use Base3\CredentialFoundation\Security\HmacRequestSigner;
use Base3\KeyHarbor\Service\CredentialManagementService;
use Base3\KeyHarbor\Service\CredentialServiceCatalog;
use Base3\KeyHarbor\Service\EncryptionProfileService;
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
