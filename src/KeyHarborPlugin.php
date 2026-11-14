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

namespace Base3\KeyHarbor;

use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Api\IPlugin;
use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\ConfigValue\Api\IConfigValueResolver;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CredentialFoundation\Api\IApiCredentialService;
use Base3\CredentialFoundation\Api\IEncryptionProfileService;
use Base3\CredentialFoundation\Api\ICredentialAccess;
use Base3\CredentialFoundation\Api\ICredentialOperationAccess;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\Repository\DatabaseCredentialRepository;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
use Base3\KeyHarbor\Security\CredentialTokenService;
use Base3\CredentialFoundation\Security\HmacRequestSigner;
use Base3\KeyHarbor\Service\ApiCredentialService;
use Base3\KeyHarbor\Service\CredentialManagementService;
use Base3\KeyHarbor\Service\CredentialServiceCatalog;
use Base3\KeyHarbor\Service\EncryptionProfileService;

final class KeyHarborPlugin implements IPlugin {

	public function __construct(
		private readonly IContainer $container
	) {}

	public static function getName(): string {
		return 'keyharborplugin';
	}

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(
				CredentialTokenService::class,
				fn() => new CredentialTokenService(),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				CredentialSecretCipher::class,
				fn($c) => new CredentialSecretCipher(
					$c->get(IConfiguration::class),
					$c->get(IConfigValueResolver::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				HmacRequestSigner::class,
				fn() => new HmacRequestSigner(),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ICredentialRepository::class,
				fn($c) => new DatabaseCredentialRepository($c->get(IDatabase::class)),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				CredentialServiceCatalog::class,
				fn($c) => new CredentialServiceCatalog($c->get(IClassMap::class)),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				IApiCredentialService::class,
				fn($c) => new ApiCredentialService(
					$c->get(ICredentialRepository::class),
					$c->get(CredentialTokenService::class),
					$c->get(CredentialServiceCatalog::class),
					$c->get(CredentialSecretCipher::class),
					$c->get(HmacRequestSigner::class),
					$c->get(IStateStore::class),
					$c->get(IConfiguration::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				ICredentialAccess::class,
				IApiCredentialService::class,
				IContainer::ALIAS
			)
			->set(
				ICredentialOperationAccess::class,
				IApiCredentialService::class,
				IContainer::ALIAS
			)
			->set(
				EncryptionProfileService::class,
				fn($c) => new EncryptionProfileService(
					$c->get(ISettingsStore::class),
					$c->get(IConfigValueResolver::class),
					$c->get(IClassMap::class),
					$c->get(CredentialSecretCipher::class),
					$c->get(CredentialManagementService::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(
				IEncryptionProfileService::class,
				EncryptionProfileService::class,
				IContainer::ALIAS
			)
			->set(
				CredentialManagementService::class,
				fn($c) => new CredentialManagementService(
					$c->get(IUsermanager::class),
					$c->get(IAuthorizationService::class),
					$c->get(ICredentialRepository::class),
					$c->get(CredentialTokenService::class),
					$c->get(CredentialServiceCatalog::class),
					$c->get(CredentialSecretCipher::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			);
	}
}
