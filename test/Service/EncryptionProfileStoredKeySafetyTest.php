<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\Crypt\Api\ICrypt;
use Base3\Usermanager\Api\IUsermanager;
use Base3\Usermanager\User;
use CredentialFoundation\Api\IEncryptionTargetProvider;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Exception\EncryptionProfileValidationException;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Service\CredentialManagementService;
use KeyHarbor\Service\CredentialServiceCatalog;
use KeyHarbor\Service\EncryptionProfileService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptionProfileStoredKeySafetyTest extends TestCase {

	public function testReadKeyRejectsUnsupportedPersistedSourceMode(): void {
		$settings = $this->settingsWithProfile(['mode' => 'legacy-secret']);
		$service = $this->service($settings);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unsupported stored encryption key source.');
		$service->getReadKey('xchacha20poly1305-v1', 'key-2026');
	}

	public function testReadKeyRejectsMissingOrNonStringResolvedSecrets(): void {
		foreach ([null, '', false, ['secret' => 'unsafe']] as $resolved) {
			$service = $this->service(
				$this->settingsWithProfile(['mode' => 'env', 'name' => 'SECRET']),
				$resolved
			);
			try {
				$service->getReadKey('xchacha20poly1305-v1', 'key-2026');
				self::fail('Unresolved encryption key material must not be returned.');
			} catch (RuntimeException $exception) {
				self::assertSame('Encryption key source did not resolve to a secret.', $exception->getMessage());
			}
		}
	}

	public function testAssignedWriteKeyRejectsUnknownStoredSourceBeforeReturningMaterial(): void {
		$settings = $this->settingsWithProfile(['mode' => 'obsolete-source']);
		$settings->set('keyharbor_encryption_targets', 'example:store', ['profile_id' => 'profile-a']);
		$service = $this->service($settings);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unsupported stored encryption key source.');
		$service->getWriteKey('example:store');
	}

	public function testAdminProfileListNeverIncludesEncryptedSecretMaterial(): void {
		$settings = $this->settingsWithProfile([
			'mode' => 'fixed', 'ciphertext' => 'stored-ciphertext', 'nonce' => 'stored-nonce',
			'secret' => 'should-not-appear'
		]);
		$profiles = $this->service($settings)->listProfilesForAdmin();

		self::assertCount(1, $profiles);
		self::assertSame([['id' => 'key-2026', 'mode' => 'fixed']], $profiles[0]['keys']);
		self::assertSame('profile-a', $profiles[0]['id']);
		self::assertSame('key-2026', $profiles[0]['key_id']);
		self::assertStringNotContainsString('stored-ciphertext', json_encode($profiles, JSON_THROW_ON_ERROR));
		self::assertStringNotContainsString('should-not-appear', json_encode($profiles, JSON_THROW_ON_ERROR));
	}

	public function testNewConfigurationSourceFieldsAreTrimmedAndRetainedAsMetadata(): void {
		$settings = new EncryptionTestSettingsStore();
		$service = $this->service($settings);
		$service->saveProfileForAdmin([
			'id' => 'profile-a', 'label' => 'Config profile', 'method' => 'xchacha20poly1305-v1',
			'key_id' => 'key-2026',
			'keys' => [['id' => 'key-2026', 'mode' => 'configuration', 'group' => ' settings ', 'key' => ' secret ']]
		]);

		self::assertSame([
			'mode' => 'configuration', 'group' => 'settings', 'key' => 'secret'
		], $settings->get('keyharbor_encryption_profiles', 'profile-a')['keys']['key-2026']);
		self::assertSame([[
			'id' => 'key-2026', 'mode' => 'configuration', 'group' => 'settings', 'key' => 'secret'
		]], $service->listProfilesForAdmin()[0]['keys']);
		self::assertSame(str_repeat('s', 40), $service->getReadKey('xchacha20poly1305-v1', 'key-2026'));
	}

	public function testCorruptPersistedKeyMapCannotBeOverwrittenBySavingAProfile(): void {
		$settings = new EncryptionTestSettingsStore();
		$settings->set('keyharbor_encryption_profiles', 'profile-a', [
			'label' => 'Existing', 'method' => 'xchacha20poly1305-v1',
			'key_id' => 'key-2026', 'keys' => false
		]);
		$before = $settings->get('keyharbor_encryption_profiles', 'profile-a');
		$service = $this->service($settings);

		try {
			$service->saveProfileForAdmin([
				'id' => 'profile-a', 'label' => 'Replacement', 'method' => 'xchacha20poly1305-v1',
				'key_id' => 'key-2026', 'keys' => [['id' => 'key-2026', 'mode' => 'env', 'name' => 'NEW_KEY']]
			]);
			self::fail('A corrupted persisted key record must be rejected.');
		} catch (RuntimeException $exception) {
			self::assertSame('Invalid stored encryption keys.', $exception->getMessage());
		}
		self::assertSame($before, $settings->get('keyharbor_encryption_profiles', 'profile-a'));
	}

	public function testAssignmentRejectsAnUnresolvableKeyAndDoesNotPersistTarget(): void {
		$settings = $this->settingsWithProfile(['mode' => 'env', 'name' => 'MISSING_KEY']);
		$service = $this->service($settings, '');

		try {
			$service->assignForAdmin('example:store', 'profile-a');
			self::fail('An unresolvable key cannot be assigned for writes.');
		} catch (RuntimeException $exception) {
			self::assertSame('Encryption key source did not resolve to a secret.', $exception->getMessage());
		}
		self::assertSame([], $settings->getGroup('keyharbor_encryption_targets'));
	}

	public function testRemovingAnUnassignedTargetDoesNotCreateAnAssignment(): void {
		$settings = new EncryptionTestSettingsStore();
		$service = $this->service($settings);
		$service->assignForAdmin('example:store', null);

		self::assertSame([], $settings->getGroup('keyharbor_encryption_targets'));
		self::assertNull($service->getAssignedProfileId('example:store'));
	}

	public function testUnsupportedSourceFieldsAreRejectedBeforePersistence(): void {
		foreach (['env' => ['name'], 'file' => ['path'], 'configuration' => ['group', 'key']] as $mode => $required) {
			foreach ($required as $field) {
				$settings = new EncryptionTestSettingsStore();
				$input = [
					'id' => 'profile-a', 'label' => 'Key profile', 'method' => 'xchacha20poly1305-v1',
					'key_id' => 'key-2026', 'keys' => [[
						'id' => 'key-2026', 'mode' => $mode,
						'name' => 'NAME', 'path' => '/key', 'group' => 'config', 'key' => 'name'
					]]
				];
				$input['keys'][0][$field] = [];
				try {
					$this->service($settings)->saveProfileForAdmin($input);
					self::fail('Non-string source fields cannot be persisted.');
				} catch (EncryptionProfileValidationException $exception) {
					self::assertSame('source_fields_required', $exception->getReason());
				}
				self::assertSame([], $settings->getGroup('keyharbor_encryption_profiles'));
			}
		}
	}

	private function settingsWithProfile(array $definition): EncryptionTestSettingsStore {
		$settings = new EncryptionTestSettingsStore();
		$settings->set('keyharbor_encryption_profiles', 'profile-a', [
			'label' => 'Test profile', 'method' => 'xchacha20poly1305-v1',
			'key_id' => 'key-2026', 'keys' => ['key-2026' => $definition]
		]);
		return $settings;
	}

	private function service(EncryptionTestSettingsStore $settings, mixed $resolved = 'ssssssssssssssssssssssssssssssssssssssss'): EncryptionProfileService {
		$config = $this->createStub(IConfiguration::class);
		$config->method('getValue')->willReturn(base64_encode(str_repeat('m', 32)));
		$resolver = $this->createStub(IConfigValueResolver::class);
		$resolver->method('resolve')->willReturn($resolved);
		$cipher = new CredentialSecretCipher($config, $resolver);
		$crypt = $this->createStub(ICrypt::class);
		$crypt->method('getMethod')->willReturn('xchacha20poly1305-v1');
		$classMap = new EncryptionTestClassMap([
			IEncryptionTargetProvider::class => [new EncryptionExampleTargetProvider()],
			ICrypt::class => [$crypt]
		]);
		$identity = $this->createStub(IUsermanager::class);
		$user = new User();
		$user->id = 42;
		$identity->method('getUser')->willReturn($user);
		$authorization = $this->createStub(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('test');
		$authorization->method('decide')->willReturn(AuthorizationDecision::allow());
		$management = new CredentialManagementService(
			$identity, $authorization, $this->createStub(ICredentialRepository::class),
			new CredentialTokenService(), new CredentialServiceCatalog($classMap), $cipher
		);
		return new EncryptionProfileService($settings, $resolver, $classMap, $cipher, $management);
	}
}

require_once __DIR__ . '/EncryptionTestSettingsStore.php';
require_once __DIR__ . '/EncryptionTestClassMap.php';
require_once __DIR__ . '/EncryptionExampleTargetProvider.php';
