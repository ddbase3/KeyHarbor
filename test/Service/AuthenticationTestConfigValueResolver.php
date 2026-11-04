<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Api\IClassMap;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\State\Api\IStateStore;
use CredentialFoundation\Api\ICredentialServiceProvider;
use CredentialFoundation\Dto\CredentialAuthenticationResult;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use CredentialFoundation\Dto\HmacAuthenticationRequest;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Service\ApiCredentialService;
use KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;

final class AuthenticationTestConfigValueResolver implements IConfigValueResolver {
	public static function getName(): string { return 'authenticationtestconfigvalueresolver'; }
	public function resolve(array|string|int|float|bool|null $config): mixed { return base64_encode(str_repeat('h', 32)); }
	public function getModes(): array { return []; }
	public function getModeSchema(string $mode): ?array { return null; }
	public function getModeSchemas(): array { return []; }
	public function getModeResolverNames(): array { return []; }
}
