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

final class EncryptionProfileServiceTest extends TestCase {

	public function testExternalKeySourceAndAssignmentAreIndependentOfConversationStorage(): void {
		$service = $this->service();
		$service->saveProfileForAdmin($this->profile());
		self::assertSame([['id' => 'key-2026', 'mode' => 'env', 'name' => 'KEY_2026']],
			$service->listProfilesForAdmin()[0]['keys']);
		self::assertSame('profile-a', $service->listProfilesForAdmin()[0]['id']);
		$service->assignForAdmin('example:store', 'profile-a');
		self::assertSame('profile-a', $service->getAssignedProfileId('example:store'));
		$key = $service->getWriteKey('example:store');
		self::assertSame('key-2026', $key?->getKeyId());
		self::assertSame('xchacha20poly1305-v1', $key?->getMethod());
		self::assertSame(str_repeat('e', 40), $service->getReadKey('xchacha20poly1305-v1', 'key-2026'));
		self::assertNull($service->getWriteKey('example:unused'));
	}

	public function testStoredProfilesAreNotDeletableThroughTheService(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$service->saveProfileForAdmin($this->profile());
		self::assertFalse(method_exists($service, 'deleteProfileForAdmin'));
		self::assertSame('Test profile', $store->get('keyharbor_encryption_profiles', 'profile-a')['label']);
	}

	public function testAddingKeysDoesNotAlterPersistedKeySources(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$service->saveProfileForAdmin($this->profile());
		$oldDefinition = $store->get('keyharbor_encryption_profiles', 'profile-a')['keys']['key-2026'];
		$updated = $this->profile();
		$updated['keys'][] = ['id' => 'key-2027', 'mode' => 'file', 'path' => '/safe/2027.key'];
		$updated['key_id'] = 'key-2027';
		$service->saveProfileForAdmin($updated);
		$after = $store->get('keyharbor_encryption_profiles', 'profile-a');
		self::assertSame($oldDefinition, $after['keys']['key-2026']);
		self::assertSame('key-2027', $after['key_id']);
		self::assertCount(2, $after['keys']);
	}

	public function testStoredKeysCannotBeRemovedRenamedOrModifiedEvenByDirectServerCalls(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$service->saveProfileForAdmin($this->profile());
		$original = $store->get('keyharbor_encryption_profiles', 'profile-a');
		$mutations = [];
		$missing = $this->profile();
		$missing['keys'] = [['id' => 'key-2027', 'mode' => 'env', 'name' => 'KEY_2027']];
		$missing['key_id'] = 'key-2027';
		$mutations[] = $missing;
		$changedSource = $this->profile();
		$changedSource['keys'][0]['name'] = 'DIFFERENT';
		$mutations[] = $changedSource;
		$changedMode = $this->profile();
		$changedMode['keys'][0] = ['id' => 'key-2026', 'mode' => 'file', 'path' => '/safe/2026.key'];
		$mutations[] = $changedMode;
		foreach ($mutations as $mutation) {
			try {
				$service->saveProfileForAdmin($mutation);
				self::fail('Stored key mutation must not succeed.');
			} catch (InvalidArgumentException $exception) {
				self::assertMatchesRegularExpression('/cannot|immutable/', $exception->getMessage());
			}
			self::assertSame($original, $store->get('keyharbor_encryption_profiles', 'profile-a'));
		}
	}

	public function testFixedKeyRotationKeepsOldKeysReadableAndCreatesANewRandomSecret(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$data = $this->profile();
		$data['keys'] = [['id' => 'key-2026', 'mode' => 'fixed', 'secret' => str_repeat('s', 40)]];
		$service->saveProfileForAdmin($data);
		$before = $store->get('keyharbor_encryption_profiles', 'profile-a');
		$newId = $service->rotateFixedKeyForAdmin('profile-a');
		self::assertMatchesRegularExpression('/^key-[a-f0-9]{24}$/', $newId);
		$after = $store->get('keyharbor_encryption_profiles', 'profile-a');
		self::assertSame($before['keys']['key-2026'], $after['keys']['key-2026']);
		self::assertSame($newId, $after['key_id']);
		self::assertCount(2, $after['keys']);
		self::assertSame(str_repeat('s', 40), $service->getReadKey('xchacha20poly1305-v1', 'key-2026'));
		$newSecret = $service->getReadKey('xchacha20poly1305-v1', $newId);
		self::assertGreaterThanOrEqual(32, strlen($newSecret));
		self::assertNotSame(str_repeat('s', 40), $newSecret);
		self::assertStringNotContainsString($newSecret, serialize($after));
		$service->assignForAdmin('example:store', 'profile-a');
		self::assertSame($newId, $service->getWriteKey('example:store')?->getKeyId());
	}

	public function testFixedSecretReplacementIsRejectedEvenWhenOriginalIdIsKept(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$data = $this->profile();
		$data['keys'] = [['id' => 'key-2026', 'mode' => 'fixed', 'secret' => str_repeat('s', 40)]];
		$service->saveProfileForAdmin($data);
		$original = $store->get('keyharbor_encryption_profiles', 'profile-a');
		$data['keys'][0]['secret'] = str_repeat('z', 40);
		try {
			$service->saveProfileForAdmin($data);
			self::fail('Fixed secret must not be replaced.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('cannot be replaced', $exception->getMessage());
		}
		self::assertSame($original, $store->get('keyharbor_encryption_profiles', 'profile-a'));
	}

	public function testAutomaticRotationRequiresAFixedActiveKey(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$service->saveProfileForAdmin($this->profile());
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('active fixed key');
		$service->rotateFixedKeyForAdmin('profile-a');
	}

	public function testRejectsKeyIdCollisionAcrossProfiles(): void {
		$service = $this->service();
		$service->saveProfileForAdmin($this->profile());
		$second = $this->profile();
		$second['id'] = 'profile-b';
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('globally unique');
		$service->saveProfileForAdmin($second);
	}

	public function testFixedSecretIsEncryptedBeforePersistenceAndNeverExposedToTheUi(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$data = $this->profile();
		$data['keys'] = [['id' => 'key-2026', 'mode' => 'fixed', 'secret' => str_repeat('s', 40)]];
		$service->saveProfileForAdmin($data);
		$raw = $store->get('keyharbor_encryption_profiles', 'profile-a');
		self::assertArrayNotHasKey('secret', $raw['keys']['key-2026']);
		self::assertStringNotContainsString(str_repeat('s', 40), serialize($raw));
		self::assertArrayNotHasKey('ciphertext', $service->listProfilesForAdmin()[0]['keys'][0]);
		self::assertSame(str_repeat('s', 40), $service->getReadKey('xchacha20poly1305-v1', 'key-2026'));
		$data['keys'][0]['secret'] = '';
		$service->saveProfileForAdmin($data);
		self::assertSame($raw, $store->get('keyharbor_encryption_profiles', 'profile-a'));
	}

	public function testMissingDeploymentMasterKeyDoesNotPersistFixedSecret(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store, false);
		$profile = $this->profile();
		$profile['keys'] = [['id' => 'key-2026', 'mode' => 'fixed', 'secret' => str_repeat('s', 40)]];
		try {
			$service->saveProfileForAdmin($profile);
			self::fail('Expected missing master key configuration error.');
		} catch (CredentialHmacConfigurationException $exception) {
			self::assertStringContainsString('hmac_master_key', $exception->getMessage());
		}
		self::assertSame([], $store->getGroup('keyharbor_encryption_profiles'));
	}

	public function testReadKeyMustMatchStoredEncryptionMethod(): void {
		$service = $this->service();
		$service->saveProfileForAdmin($this->profile());
		$this->expectException(RuntimeException::class);
		$service->getReadKey('aes-256-cbc-legacy-v1', 'key-2026');
	}

	/** @return array<string,mixed> */
	private function profile(): array {
		return [
			'id' => 'profile-a', 'label' => 'Test profile', 'method' => 'xchacha20poly1305-v1',
			'key_id' => 'key-2026',
			'keys' => [['id' => 'key-2026', 'mode' => 'env', 'name' => 'KEY_2026']]
		];
	}

	private function service(?EncryptionTestSettingsStore $store = null, bool $masterKeyConfigured = true): EncryptionProfileService {
		$store ??= new EncryptionTestSettingsStore();
		$config = $this->createMock(IConfiguration::class);
		$config->method('getValue')->willReturn($masterKeyConfigured ? base64_encode(str_repeat('m', 32)) : null);
		$resolver = $this->createMock(IConfigValueResolver::class);
		$resolver->method('resolve')->willReturn(str_repeat('e', 40));
		$cipher = new CredentialSecretCipher($config, $resolver);
		$classMap = new EncryptionTestClassMap([
			IEncryptionTargetProvider::class => [new EncryptionExampleTargetProvider()],
			ICrypt::class => [$this->method('xchacha20poly1305-v1'), $this->method('aes-256-cbc-legacy-v1')]
		]);
		$identity = $this->createMock(IUsermanager::class);
		$user = new User();
		$user->id = 10;
		$identity->method('getUser')->willReturn($user);
		$authorization = $this->createMock(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('test');
		$authorization->method('decide')->willReturn(AuthorizationDecision::allow());
		$management = new CredentialManagementService(
			$identity, $authorization, $this->createMock(ICredentialRepository::class),
			new CredentialTokenService(), new CredentialServiceCatalog($classMap), $cipher
		);
		return new EncryptionProfileService($store, $resolver, $classMap, $cipher, $management);
	}

	private function method(string $name): ICrypt {
		$crypt = $this->createMock(ICrypt::class);
		$crypt->method('getMethod')->willReturn($name);
		return $crypt;
	}
}

final class EncryptionExampleTargetProvider implements IEncryptionTargetProvider {
	public static function getName(): string { return 'encryptionexampletargetprovider'; }
	public function getTargets(): array { return [new EncryptionTargetDefinition('example:store', 'Example store')]; }
}

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
