<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Api\IClassMap;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\Crypt\Api\ICrypt;
use Base3\Settings\Api\ISettingsStore;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\User;
use CredentialFoundation\Api\IEncryptionTargetProvider;
use CredentialFoundation\Dto\EncryptionTargetDefinition;
use InvalidArgumentException;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Exception\CredentialHmacConfigurationException;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Service\CredentialManagementService;
use KeyHarbor\Service\CredentialServiceCatalog;
use KeyHarbor\Service\EncryptionProfileService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptionTestClassMap implements IClassMap {
	/** @var array<string,array<int,object>> */
	private array $instances;
	private array $empty = [];
	/** @param array<string,array<int,object>> $instances */
	public function __construct(array $instances) { $this->instances = $instances; }
	public function instantiate(string $class) { return null; }
	public function instantiateWith(string $class, array $arguments = []) { return null; }
	public function generate($regenerate = false): void {}
	public function getApps() { return []; }
	public function &getInstances(array $criteria = []) { return $this->empty; }
	public function &getInstancesByInterface($interface) {
		if (!isset($this->instances[$interface])) { return $this->empty; }
		return $this->instances[$interface];
	}
	public function &getInstancesByAppInterface($app, $interface, $retry = false) { return $this->empty; }
	public function &getInstanceByAppName($app, $name, $retry = false) { $null = null; return $null; }
	public function getClassByInterfaceName(string $interface, string $name): ?string { return null; }
	public function &getInstanceByInterfaceName($interface, $name, $retry = false) { $null = null; return $null; }
	public function &getInstanceByAppInterfaceName($app, $interface, $name, $retry = false) { $null = null; return $null; }
	public function getPlugins(): array { return []; }
}
