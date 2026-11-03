<?php declare(strict_types=1);

namespace KeyHarbor\Test\Security;

use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use KeyHarbor\Exception\CredentialHmacConfigurationException;
use KeyHarbor\Security\CredentialSecretCipher;
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
