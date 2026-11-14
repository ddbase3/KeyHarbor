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

namespace Base3\KeyHarbor\Test\Security;

use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\ConfigValue\Api\IConfigValueResolver;
use Base3\KeyHarbor\Exception\CredentialHmacConfigurationException;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
use PHPUnit\Framework\TestCase;

final class CipherTestConfigValueResolver implements IConfigValueResolver {

	private int $resolveCalls = 0;

	public function __construct(
		private readonly string $resolvedValue = ''
	) {}

	public static function getName(): string {
		return 'ciphertestconfigvalueresolver';
	}

	public function resolve(array|string|int|float|bool|null $config): mixed {
		$this->resolveCalls++;
		return $this->resolvedValue;
	}

	public function getResolveCalls(): int {
		return $this->resolveCalls;
	}

	public function getModes(): array { return []; }
	public function getModeSchema(string $mode): ?array { return null; }
	public function getModeSchemas(): array { return []; }
	public function getModeResolverNames(): array { return []; }
}
