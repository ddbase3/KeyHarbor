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

use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\ConfigValue\Api\IConfigValueResolver;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CredentialFoundation\Api\IApiCredentialService;
use Base3\CredentialFoundation\Api\ICredentialAccess;
use Base3\CredentialFoundation\Api\ICredentialOperationAccess;
use Base3\CredentialFoundation\Api\IEncryptionProfileService;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\KeyHarborPlugin;
use Base3\KeyHarbor\Repository\DatabaseCredentialRepository;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
use Base3\KeyHarbor\Security\CredentialTokenService;
use Base3\CredentialFoundation\Security\HmacRequestSigner;
use Base3\KeyHarbor\Service\ApiCredentialService;
use Base3\KeyHarbor\Service\CredentialManagementService;
use Base3\KeyHarbor\Service\CredentialServiceCatalog;
use Base3\KeyHarbor\Service\EncryptionProfileService;
use PHPUnit\Framework\TestCase;

final class KeyHarborPluginFactoryWiringTest extends TestCase {

	public function testAllServiceFactoriesConstructTheirDeclaredOwners(): void {
		$registrations = [];
		$instances = [
			IConfiguration::class => $this->createStub(IConfiguration::class),
			IConfigValueResolver::class => $this->createStub(IConfigValueResolver::class),
			IDatabase::class => $this->createStub(IDatabase::class),
			IClassMap::class => $this->createStub(IClassMap::class),
			ISettingsStore::class => $this->createStub(ISettingsStore::class),
			IStateStore::class => $this->createStub(IStateStore::class),
			IUsermanager::class => $this->createStub(IUsermanager::class),
			IAuthorizationService::class => $this->createStub(IAuthorizationService::class)
		];
		$container = $this->createStub(IContainer::class);
		$container->method('set')->willReturnCallback(static function(string $name, mixed $definition, int $flags) use (&$registrations, $container): IContainer {
			$registrations[$name] = [$definition, $flags];
			return $container;
		});
		$container->method('get')->willReturnCallback(static function(string $name) use (&$instances): mixed {
			if (!array_key_exists($name, $instances)) {
				throw new \RuntimeException('Unregistered factory dependency: ' . $name);
			}
			return $instances[$name];
		});
		$plugin = new KeyHarborPlugin($container);
		$plugin->init();

		$instances[CredentialTokenService::class] = $registrations[CredentialTokenService::class][0]();
		$instances[HmacRequestSigner::class] = $registrations[HmacRequestSigner::class][0]();
		$instances[CredentialSecretCipher::class] = $registrations[CredentialSecretCipher::class][0]($container);
		$instances[ICredentialRepository::class] = $registrations[ICredentialRepository::class][0]($container);
		$instances[CredentialServiceCatalog::class] = $registrations[CredentialServiceCatalog::class][0]($container);
		$instances[CredentialManagementService::class] = $registrations[CredentialManagementService::class][0]($container);
		$instances[IApiCredentialService::class] = $registrations[IApiCredentialService::class][0]($container);
		$instances[EncryptionProfileService::class] = $registrations[EncryptionProfileService::class][0]($container);

		self::assertInstanceOf(CredentialTokenService::class, $instances[CredentialTokenService::class]);
		self::assertInstanceOf(HmacRequestSigner::class, $instances[HmacRequestSigner::class]);
		self::assertInstanceOf(CredentialSecretCipher::class, $instances[CredentialSecretCipher::class]);
		self::assertInstanceOf(DatabaseCredentialRepository::class, $instances[ICredentialRepository::class]);
		self::assertInstanceOf(CredentialServiceCatalog::class, $instances[CredentialServiceCatalog::class]);
		self::assertInstanceOf(CredentialManagementService::class, $instances[CredentialManagementService::class]);
		self::assertInstanceOf(ApiCredentialService::class, $instances[IApiCredentialService::class]);
		self::assertInstanceOf(EncryptionProfileService::class, $instances[EncryptionProfileService::class]);
		self::assertSame([IApiCredentialService::class, IContainer::ALIAS], $registrations[ICredentialAccess::class]);
		self::assertSame([IApiCredentialService::class, IContainer::ALIAS], $registrations[ICredentialOperationAccess::class]);
		self::assertSame([EncryptionProfileService::class, IContainer::ALIAS], $registrations[IEncryptionProfileService::class]);
		self::assertSame([$plugin, IContainer::SHARED], $registrations[KeyHarborPlugin::getName()]);
	}
}
