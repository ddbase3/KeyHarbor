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

final class EncryptionTestSettingsStore implements ISettingsStore {
	/** @var array<string,array<string,array<string,mixed>>> */
	private array $data = [];
	public function get(string $group, string $name, array $default = []): array { return $this->data[$group][$name] ?? $default; }
	public function set(string $group, string $name, array $settings): void { $this->data[$group][$name] = $settings; }
	public function has(string $group, string $name): bool { return isset($this->data[$group][$name]); }
	public function remove(string $group, string $name): void { unset($this->data[$group][$name]); }
	public function getGroup(string $group): array { return $this->data[$group] ?? []; }
	public function save(): void {}
	public function reload(): void {}
}
