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
use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\ConfigValue\Api\IConfigValueResolver;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\CredentialFoundation\Api\ICredentialServiceProvider;
use Base3\CredentialFoundation\Dto\CredentialAuthenticationResult;
use Base3\CredentialFoundation\Dto\CredentialServiceDefinition;
use Base3\CredentialFoundation\Dto\HmacAuthenticationRequest;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\Model\ApiCredential;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
use Base3\KeyHarbor\Security\CredentialTokenService;
use Base3\KeyHarbor\Service\ApiCredentialService;
use Base3\KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;

final class AuthenticationTestClassMap implements IClassMap {

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
