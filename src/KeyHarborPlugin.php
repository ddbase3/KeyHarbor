<?php declare(strict_types=1);

namespace KeyHarbor;

use Base3\Api\IClassMap;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\Database\Api\IDatabase;
use Base3\State\Api\IStateStore;
use Base3\Usermanager\Api\IUsermanager;
use CredentialFoundation\Api\IApiCredentialService;
use CredentialFoundation\Api\ICredentialAccess;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Repository\DatabaseCredentialRepository;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Security\HmacRequestSigner;
use KeyHarbor\Service\ApiCredentialService;
use KeyHarbor\Service\CredentialManagementService;
use KeyHarbor\Service\CredentialServiceCatalog;

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
				CredentialManagementService::class,
				fn($c) => new CredentialManagementService(
					$c->get(IUsermanager::class),
					$c->get(ICredentialRepository::class),
					$c->get(CredentialTokenService::class),
					$c->get(CredentialServiceCatalog::class),
					$c->get(CredentialSecretCipher::class)
				),
				IContainer::SHARED | IContainer::NOOVERWRITE
			);
	}
}
