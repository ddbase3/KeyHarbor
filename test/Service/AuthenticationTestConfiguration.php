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

final class AuthenticationTestConfiguration implements IConfiguration {
	public function get($configuration = '') { return []; }
	public function set($data, $configuration = ''): void {}
	public function save(): void {}
	public function getGroup(string $group, array $default = []): array { return $default; }
	public function getValue(string $group, string $key, $default = null) {
		return $group === 'keyharbor' && $key === 'hmac_master_key' ? base64_encode(str_repeat('h', 32)) : $default;
	}
	public function getString(string $group, string $key, string $default = ''): string { return $default; }
	public function getInt(string $group, string $key, int $default = 0): int { return $default; }
	public function getFloat(string $group, string $key, float $default = 0.0): float { return $default; }
	public function getBool(string $group, string $key, bool $default = false): bool { return $default; }
	public function getArray(string $group, string $key, array $default = []): array { return $default; }
	public function hasGroup(string $group): bool { return true; }
	public function hasValue(string $group, string $key): bool { return true; }
	public function setValue(string $group, string $key, $value): void {}
	public function setGroup(string $group, array $values, bool $merge = true): void {}
	public function setMany(array $data, bool $merge = true): void {}
	public function removeGroup(string $group): void {}
	public function removeValue(string $group, string $key): void {}
	public function isDirty(): bool { return false; }
	public function saveIfDirty(): bool { return true; }
	public function trySave(): bool { return true; }
	public function reload(): void {}
	public function persistValue(string $group, string $key, $value): bool { return true; }
}
