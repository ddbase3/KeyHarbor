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
use KeyHarbor\Security\HmacRequestSigner;
use KeyHarbor\Service\ApiCredentialService;
use KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;

final class ApiCredentialServiceTest extends TestCase {

	public function testAuthenticatesValidBearerCredential(): void {
		[$service, $token, $credential] = $this->serviceWithCredential();

		$result = $service->authenticateBearer($token, 'example:read');

		self::assertTrue($result->isAuthenticated());
		self::assertSame($credential->getId(), $result->getCredentialId());
		self::assertSame('42', $result->getUserId());
		self::assertSame('example:read', $result->getServiceId());
		self::assertSame($credential->getExpiresAt(), $result->getExpiresAt());
	}

	public function testRejectsMalformedAndInvalidCredentials(): void {
		[$service, $token] = $this->serviceWithCredential();

		$malformed = $service->authenticateBearer('invalid', 'example:read');
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_MALFORMED_CREDENTIAL,
			$malformed->getFailureCode()
		);

		$invalid = $service->authenticateBearer($this->changeTokenSecret($token), 'example:read');
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_INVALID_CREDENTIAL,
			$invalid->getFailureCode()
		);
	}

	public function testRejectsRevokedAndExpiredCredentials(): void {
		[$revokedService, $revokedToken] = $this->serviceWithCredential(
			['example:read'],
			time() + 3600,
			time() - 10
		);
		$revoked = $revokedService->authenticateBearer($revokedToken, 'example:read');
		self::assertSame(CredentialAuthenticationResult::FAILURE_REVOKED, $revoked->getFailureCode());

		[$expiredService, $expiredToken] = $this->serviceWithCredential(
			['example:read'],
			time() - 1
		);
		$expired = $expiredService->authenticateBearer($expiredToken, 'example:read');
		self::assertSame(CredentialAuthenticationResult::FAILURE_EXPIRED, $expired->getFailureCode());
	}

	public function testRejectsUnknownAndUngrantedServices(): void {
		[$service, $token] = $this->serviceWithCredential(['example:write']);

		$unknown = $service->authenticateBearer($token, 'missing:service');
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_FOUND,
			$unknown->getFailureCode()
		);

		$notGranted = $service->authenticateBearer($token, 'example:read');
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_GRANTED,
			$notGranted->getFailureCode()
		);
	}

	public function testAuthenticatesHmacAndRejectsReplayAndBearerDowngrade(): void {
		[$service, $token, $credential, $signer] = $this->serviceWithCredential(
			['example:read'],
			time() + 3600,
			null,
			true
		);
		$timestamp = time();
		$unsigned = new HmacAuthenticationRequest(
			$token,
			'POST',
			'/example',
			'a=1',
			$timestamp,
			'nonce-1',
			str_repeat('a', 64),
			'{"ok":true}'
		);
		$secret = (new CredentialTokenService())->parse($token)?->getSecret();
		self::assertNotNull($secret);
		$request = new HmacAuthenticationRequest(
			$token,
			'POST',
			'/example',
			'a=1',
			$timestamp,
			'nonce-1',
			$signer->sign($unsigned, $secret),
			'{"ok":true}'
		);

		self::assertTrue($service->authenticateHmac($request, 'example:read')->isAuthenticated());
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_REPLAY_DETECTED,
			$service->authenticateHmac($request, 'example:read')->getFailureCode()
		);
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_HMAC_REQUIRED,
			$service->authenticateBearer($token, 'example:read')->getFailureCode()
		);
		self::assertTrue($credential->isHmacEnabled());
	}

	public function testRejectsHmacForBearerOnlyCredential(): void {
		[$service, $token] = $this->serviceWithCredential();
		$request = new HmacAuthenticationRequest(
			$token,
			'POST',
			'/example',
			'',
			time(),
			'nonce',
			str_repeat('a', 64),
			'{}'
		);

		$result = $service->authenticateHmac($request, 'example:read');

		self::assertSame(
			CredentialAuthenticationResult::FAILURE_HMAC_NOT_ENABLED,
			$result->getFailureCode()
		);
	}

	/**
	 * @param array<int,string> $serviceIds
	 * @return array{0:ApiCredentialService,1:string,2:ApiCredential}
	 */
	private function serviceWithCredential(
		array $serviceIds = ['example:read'],
		?int $expiresAt = null,
		?int $revokedAt = null,
		bool $hmacEnabled = false
	): array {
		$tokenService = new CredentialTokenService();
		$generated = $tokenService->generate();
		$configuration = new AuthenticationTestConfiguration();
		$cipher = new CredentialSecretCipher($configuration, new AuthenticationTestConfigValueResolver());
		$encrypted = $hmacEnabled ? $cipher->encrypt($generated->getSecret()) : null;
		$credential = new ApiCredential(
			$generated->getCredentialId(),
			$generated->getPublicId(),
			'42',
			'demo',
			'Demo User',
			'demo@example.test',
			'en',
			'Demo key',
			$generated->getSecretHash(),
			$hmacEnabled,
			$encrypted?->getCiphertext(),
			$encrypted?->getNonce(),
			time() - 60,
			$expiresAt,
			$revokedAt,
			null,
			null,
			$serviceIds
		);
		$repository = new AuthenticationTestRepository([$credential]);
		$catalog = new CredentialServiceCatalog(new AuthenticationTestClassMap([
			new AuthenticationTestProvider()
		]));

		$signer = new HmacRequestSigner();
		return [
			new ApiCredentialService(
				$repository,
				$tokenService,
				$catalog,
				$cipher,
				$signer,
				new AuthenticationTestStateStore(),
				$configuration
			),
			$generated->getToken(),
			$credential,
			$signer
		];
	}

	private function changeTokenSecret(string $token): string {
		$last = substr($token, -1);
		return substr($token, 0, -1) . ($last === 'A' ? 'B' : 'A');
	}
}

final class AuthenticationTestProvider implements ICredentialServiceProvider {

	public static function getName(): string {
		return 'authenticationtestprovider';
	}

	public function getServices(): array {
		return [
			new CredentialServiceDefinition('example:read', 'Example read'),
			new CredentialServiceDefinition('example:write', 'Example write')
		];
	}
}

final class AuthenticationTestRepository implements ICredentialRepository {

	/** @var array<string,ApiCredential> */
	private array $credentialsByPublicId = [];

	/** @param array<int,ApiCredential> $credentials */
	public function __construct(array $credentials) {
		foreach ($credentials as $credential) {
			$this->credentialsByPublicId[$credential->getPublicId()] = $credential;
		}
	}

	public function insert(ApiCredential $credential): void {
		$this->credentialsByPublicId[$credential->getPublicId()] = $credential;
	}

	public function getById(string $id): ?ApiCredential {
		foreach ($this->credentialsByPublicId as $credential) {
			if ($credential->getId() === $id) {
				return $credential;
			}
		}
		return null;
	}

	public function getByPublicId(string $publicId): ?ApiCredential {
		return $this->credentialsByPublicId[$publicId] ?? null;
	}

	public function getByOwner(string $id, int|string $ownerUserId): ?ApiCredential {
		$credential = $this->getById($id);
		if ($credential === null || $credential->getOwnerUserId() !== (string)$ownerUserId) {
			return null;
		}
		return $credential;
	}

	public function listByOwner(int|string $ownerUserId): array {
		return array_values(array_filter(
			$this->credentialsByPublicId,
			fn(ApiCredential $credential): bool => $credential->getOwnerUserId() === (string)$ownerUserId
		));
	}

	public function listAll(): array {
		return array_values($this->credentialsByPublicId);
	}

	public function update(ApiCredential $credential): bool {
		if ($this->getById($credential->getId()) === null) {
			return false;
		}
		$this->credentialsByPublicId[$credential->getPublicId()] = $credential;
		return true;
	}

	public function updateForOwner(ApiCredential $credential, int|string $ownerUserId): bool {
		if ($this->getByOwner($credential->getId(), $ownerUserId) === null) {
			return false;
		}
		return $this->update($credential);
	}

	public function deleteRevoked(string $id): bool {
		return false;
	}

	public function deleteRevokedForOwner(string $id, int|string $ownerUserId): bool {
		return false;
	}

	public function findExpiring(int $fromExclusive, int $toInclusive): array {
		return [];
	}

	public function findExpired(int $now): array {
		return [];
	}

	public function markWarningNotified(string $id, int $notifiedAt): bool {
		return $this->getById($id) !== null;
	}

	public function markExpiryNotified(string $id, int $notifiedAt): bool {
		return $this->getById($id) !== null;
	}
}

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

final class AuthenticationTestStateStore implements IStateStore {
	private array $values = [];
	public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
	public function has(string $key): bool { return array_key_exists($key, $this->values); }
	public function set(string $key, mixed $value, ?int $ttlSeconds = null): void { $this->values[$key] = $value; }
	public function delete(string $key): bool { $exists = $this->has($key); unset($this->values[$key]); return $exists; }
	public function setIfNotExists(string $key, mixed $value, ?int $ttlSeconds = null): bool {
		if ($this->has($key)) { return false; }
		$this->values[$key] = $value;
		return true;
	}
	public function listKeys(string $prefix): array { return []; }
	public function flush(): void {}
}

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

final class AuthenticationTestConfigValueResolver implements IConfigValueResolver {
	public static function getName(): string { return 'authenticationtestconfigvalueresolver'; }
	public function resolve(array|string|int|float|bool|null $config): mixed { return base64_encode(str_repeat('h', 32)); }
	public function getModes(): array { return []; }
	public function getModeSchema(string $mode): ?array { return null; }
	public function getModeSchemas(): array { return []; }
	public function getModeResolverNames(): array { return []; }
}
