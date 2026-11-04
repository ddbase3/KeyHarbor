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
		$data['keys'] = [['id' => 'key-2026', 'mode' => 'fixed']];
		$service->saveProfileForAdmin($data);
		$before = $store->get('keyharbor_encryption_profiles', 'profile-a');
		$oldSecret = $service->getReadKey('xchacha20poly1305-v1', 'key-2026');
		$newId = $service->rotateFixedKeyForAdmin('profile-a');
		self::assertMatchesRegularExpression('/^key-[a-f0-9]{24}$/', $newId);
		$after = $store->get('keyharbor_encryption_profiles', 'profile-a');
		self::assertSame($before['keys']['key-2026'], $after['keys']['key-2026']);
		self::assertSame($newId, $after['key_id']);
		self::assertCount(2, $after['keys']);
		self::assertSame($oldSecret, $service->getReadKey('xchacha20poly1305-v1', 'key-2026'));
		$newSecret = $service->getReadKey('xchacha20poly1305-v1', $newId);
		self::assertGreaterThanOrEqual(32, strlen($newSecret));
		self::assertNotSame($oldSecret, $newSecret);
		self::assertStringNotContainsString($newSecret, serialize($after));
		$service->assignForAdmin('example:store', 'profile-a');
		self::assertSame($newId, $service->getWriteKey('example:store')?->getKeyId());
	}

	public function testFixedSecretReplacementIsRejectedEvenWhenOriginalIdIsKept(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$data = $this->profile();
		$data['keys'] = [['id' => 'key-2026', 'mode' => 'fixed']];
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
		$data['keys'] = [['id' => 'key-2026', 'mode' => 'fixed']];
		$service->saveProfileForAdmin($data);
		$raw = $store->get('keyharbor_encryption_profiles', 'profile-a');
		$generatedSecret = $service->getReadKey('xchacha20poly1305-v1', 'key-2026');
		self::assertGreaterThanOrEqual(32, strlen($generatedSecret));
		self::assertArrayNotHasKey('secret', $raw['keys']['key-2026']);
		self::assertStringNotContainsString($generatedSecret, serialize($raw));
		self::assertArrayNotHasKey('ciphertext', $service->listProfilesForAdmin()[0]['keys'][0]);
		self::assertSame($generatedSecret, $service->getReadKey('xchacha20poly1305-v1', 'key-2026'));
		$data['keys'][0]['secret'] = '';
		$service->saveProfileForAdmin($data);
		self::assertSame($raw, $store->get('keyharbor_encryption_profiles', 'profile-a'));
	}

	public function testMissingDeploymentMasterKeyDoesNotPersistFixedSecret(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store, false);
		$profile = $this->profile();
		$profile['keys'] = [['id' => 'key-2026', 'mode' => 'fixed']];
		try {
			$service->saveProfileForAdmin($profile);
			self::fail('Expected missing master key configuration error.');
		} catch (CredentialHmacConfigurationException $exception) {
			self::assertStringContainsString('hmac_master_key', $exception->getMessage());
		}
		self::assertSame([], $store->getGroup('keyharbor_encryption_profiles'));
	}

	public function testNewProfileWithoutKeysGeneratesAndPersistsAnEncryptedFixedKey(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$service->saveProfileForAdmin([
			'id' => 'profile-auto', 'label' => 'Automatically protected',
			'method' => 'xchacha20poly1305-v1', 'key_id' => '', 'keys' => []
		]);
		$raw = $store->get('keyharbor_encryption_profiles', 'profile-auto');
		$keyId = $raw['key_id'];
		self::assertMatchesRegularExpression('/^key-[a-f0-9]{24}$/', $keyId);
		self::assertSame(['mode', 'ciphertext', 'nonce'], array_keys($raw['keys'][$keyId]));
		$secret = $service->getReadKey('xchacha20poly1305-v1', $keyId);
		self::assertGreaterThanOrEqual(32, strlen($secret));
		self::assertStringNotContainsString($secret, serialize($raw));
		self::assertArrayNotHasKey('ciphertext', $service->listProfilesForAdmin()[0]['keys'][0]);
		$service->assignForAdmin('example:store', 'profile-auto');
		self::assertSame($secret, $service->getWriteKey('example:store')?->getSecret());
	}

	public function testNewFixedEntryWithoutIdentifierOrSecretIsGeneratedAndDoesNotChangePriorKeys(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$service->saveProfileForAdmin($this->profile());
		$prior = $store->get('keyharbor_encryption_profiles', 'profile-a');
		$updated = $this->profile();
		$updated['keys'][] = ['id' => '', 'mode' => 'fixed'];
		$service->saveProfileForAdmin($updated);
		$after = $store->get('keyharbor_encryption_profiles', 'profile-a');
		self::assertSame($prior['keys']['key-2026'], $after['keys']['key-2026']);
		self::assertSame('key-2026', $after['key_id']);
		self::assertCount(2, $after['keys']);
		$newId = array_values(array_diff(array_keys($after['keys']), ['key-2026']))[0];
		self::assertMatchesRegularExpression('/^key-[a-f0-9]{24}$/', $newId);
		self::assertGreaterThanOrEqual(32, strlen($service->getReadKey('xchacha20poly1305-v1', $newId)));
	}

	public function testNewSingleFixedEntryAutomaticallyBecomesActiveWithoutAnIdentifier(): void {
		$service = $this->service();
		$service->saveProfileForAdmin([
			'id' => 'profile-new', 'label' => 'Fixed test',
			'method' => 'xchacha20poly1305-v1', 'key_id' => '',
			'keys' => [['id' => '', 'mode' => 'fixed']]
		]);
		$profile = $service->listProfilesForAdmin()[0];
		self::assertMatchesRegularExpression('/^key-[a-f0-9]{24}$/', $profile['key_id']);
		self::assertSame($profile['key_id'], $profile['keys'][0]['id']);
	}

	public function testFixedKeyWithExplicitIdSavesWithoutManuallyEnteredSecret(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$service->saveProfileForAdmin([
			'id' => 'profile-fixed', 'label' => 'Fixed generated',
			'method' => 'xchacha20poly1305-v1', 'key_id' => 'key-custom',
			'keys' => [['id' => 'key-custom', 'mode' => 'fixed']]
		]);
		$stored = $store->get('keyharbor_encryption_profiles', 'profile-fixed');
		self::assertSame('key-custom', $stored['key_id']);
		self::assertSame('fixed', $stored['keys']['key-custom']['mode']);
		self::assertGreaterThanOrEqual(32, strlen($service->getReadKey('xchacha20poly1305-v1', 'key-custom')));
	}

	public function testRejectsManuallySuppliedFixedSecretInsteadOfSilentlyReplacingIt(): void {
		$store = new EncryptionTestSettingsStore();
		$service = $this->service($store);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('generated by KeyHarbor');
		$data = $this->profile();
		$data['keys'] = [['id' => 'key-2026', 'mode' => 'fixed', 'secret' => str_repeat('s', 40)]];
		$service->saveProfileForAdmin($data);
	}

	public function testReadKeyMustMatchStoredEncryptionMethod(): void {
		$service = $this->service();
		$service->saveProfileForAdmin($this->profile());
		$this->expectException(RuntimeException::class);
		$service->getReadKey('aes-256-cbc-legacy-v1', 'key-2026');
	}

	public function testUnassignedTargetDoesNotExposeEncryptionKeyMaterial(): void {
		$service = $this->service();
		self::assertNull($service->getAssignedProfileId('example:store'));
		self::assertNull($service->getWriteKey('example:store'));
	}

	public function testEncryptionMethodsAndTargetsAreDiscoveredInStableOrder(): void {
		$service = $this->service();
		self::assertSame(['aes-256-cbc-legacy-v1', 'xchacha20poly1305-v1'], $service->getMethodsForAdmin());
		$targets = $service->listTargetsForAdmin();
		self::assertCount(1, $targets);
		self::assertSame('example:store', $targets[0]->getTargetId());
	}

	public function testAssignmentsAreReturnedSortedByTargetIdentifier(): void {
		$settings = new EncryptionTestSettingsStore();
		$settings->set('keyharbor_encryption_targets', 'target-z', ['profile_id' => 'profile-z']);
		$settings->set('keyharbor_encryption_targets', 'target-a', ['profile_id' => 'profile-a']);
		self::assertSame([
			'target-a' => 'profile-a', 'target-z' => 'profile-z'
		], $this->service($settings)->listAssignmentsForAdmin());
	}

	public function testStoredInvalidAssignmentDoesNotDefaultToAnUnrestrictedProfile(): void {
		foreach ([['profile_id' => 'INVALID'], ['profile_id' => 42], ['other' => 'profile-a']] as $invalid) {
			$settings = new EncryptionTestSettingsStore();
			$settings->set('keyharbor_encryption_targets', 'example:store', $invalid);
			try {
				$this->service($settings)->getAssignedProfileId('example:store');
				self::fail('Invalid stored assignments must not resolve.');
			} catch (RuntimeException $exception) {
				self::assertStringContainsString('Invalid stored encryption assignment', $exception->getMessage());
			}
		}
	}

	public function testListAssignmentsRejectsInvalidPersistedData(): void {
		$settings = new EncryptionTestSettingsStore();
		$settings->set('keyharbor_encryption_targets', 'example:store', ['profile_id' => 42]);
		$this->expectException(\UnexpectedValueException::class);
		$this->service($settings)->listAssignmentsForAdmin();
	}

	public function testMissingProfileAssignmentCannotProduceWriteKey(): void {
		$settings = new EncryptionTestSettingsStore();
		$settings->set('keyharbor_encryption_targets', 'example:store', ['profile_id' => 'missing-profile']);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Encryption profile is missing');
		$this->service($settings)->getWriteKey('example:store');
	}

	public function testAssignedProfileWithoutActiveKeyCannotProduceWriteKey(): void {
		$settings = new EncryptionTestSettingsStore();
		$settings->set('keyharbor_encryption_profiles', 'profile-a', [
			'key_id' => '', 'method' => 'xchacha20poly1305-v1', 'keys' => []
		]);
		$settings->set('keyharbor_encryption_targets', 'example:store', ['profile_id' => 'profile-a']);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('lacks an active key or method');
		$this->service($settings)->getWriteKey('example:store');
	}

	public function testDuplicateHistoricalKeyIdsAreRejectedOnRead(): void {
		$settings = new EncryptionTestSettingsStore();
		foreach (['profile-a', 'profile-b'] as $id) {
			$settings->set('keyharbor_encryption_profiles', $id, [
				'method' => 'xchacha20poly1305-v1', 'key_id' => 'key-2026',
				'keys' => ['key-2026' => ['mode' => 'env', 'name' => 'EXAMPLE']]
			]);
		}
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Duplicate historical encryption key ID');
		$this->service($settings)->getReadKey('xchacha20poly1305-v1', 'key-2026');
	}

	public function testUnknownHistoricalKeyIsRejected(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Historical encryption key is unavailable');
		$this->service()->getReadKey('xchacha20poly1305-v1', 'key-unknown');
	}

	public function testInvalidTargetIdentifiersAreRejectedBeforeAssignments(): void {
		$service = $this->service();
		foreach (['', 'INVALID', 'bad name', str_repeat('a', 129)] as $targetId) {
			try {
				$service->getAssignedProfileId($targetId);
				self::fail('Invalid target ID must not be accepted.');
			} catch (\KeyHarbor\Exception\EncryptionProfileValidationException $exception) {
				self::assertSame('invalid_identifier', $exception->getReason());
			}
		}
	}

	public function testAssigningUnknownTargetIsRejectedWithoutPersistence(): void {
		$settings = new EncryptionTestSettingsStore();
		$service = $this->service($settings);
		$service->saveProfileForAdmin($this->profile());
		try {
			$service->assignForAdmin('other:store', 'profile-a');
			self::fail('Undeclared targets must not be assigned.');
		} catch (\KeyHarbor\Exception\EncryptionProfileValidationException $exception) {
			self::assertSame('target_unavailable', $exception->getReason());
		}
		self::assertSame([], $settings->getGroup('keyharbor_encryption_targets'));
	}

	public function testAssigningMissingProfileIsRejectedWithoutPersistence(): void {
		$settings = new EncryptionTestSettingsStore();
		$service = $this->service($settings);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Encryption profile is missing');
		$service->assignForAdmin('example:store', 'missing-profile');
	}

	public function testRepeatedAssignmentDoesNotNeedNewConfirmation(): void {
		$service = $this->service();
		$service->saveProfileForAdmin($this->profile());
		$service->assignForAdmin('example:store', 'profile-a');
		$service->assignForAdmin('example:store', 'profile-a');
		self::assertSame('profile-a', $service->getAssignedProfileId('example:store'));
	}

	public function testRemovingExistingAssignmentRequiresConfirmation(): void {
		$settings = new EncryptionTestSettingsStore();
		$service = $this->service($settings);
		$service->saveProfileForAdmin($this->profile());
		$service->assignForAdmin('example:store', 'profile-a');
		try {
			$service->assignForAdmin('example:store', null);
			self::fail('Assignment removal must be confirmed.');
		} catch (\KeyHarbor\Exception\EncryptionProfileValidationException $exception) {
			self::assertSame('assignment_confirmation', $exception->getReason());
		}
		self::assertSame('profile-a', $service->getAssignedProfileId('example:store'));
		$service->assignForAdmin('example:store', null, true);
		self::assertNull($service->getAssignedProfileId('example:store'));
	}

	public function testPersistedProfilesAreListedInSortedOrder(): void {
		$service = $this->service();
		$first = $this->profile();
		$first['id'] = 'profile-z';
		$first['keys'][0]['id'] = 'key-z';
		$first['key_id'] = 'key-z';
		$service->saveProfileForAdmin($first);
		$last = $this->profile();
		$last['id'] = 'profile-a';
		$service->saveProfileForAdmin($last);
		self::assertSame(['profile-a', 'profile-z'], array_column($service->listProfilesForAdmin(), 'id'));
	}

	public function testInvalidStoredKeySourceRecordsAreNotSerializedToAdmin(): void {
		$settings = new EncryptionTestSettingsStore();
		$settings->set('keyharbor_encryption_profiles', 'profile-a', [
			'label' => 'Broken', 'method' => 'xchacha20poly1305-v1',
			'key_id' => 'key-a', 'keys' => ['key-a' => 42]
		]);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Invalid encryption key source record');
		$this->service($settings)->listProfilesForAdmin();
	}

	public function testSaveProfileRejectsInvalidFieldsWithoutPersistence(): void {
		$base = $this->profile();
		$cases = [
			['id', 'INVALID', 'invalid_identifier'],
			['label', '', 'profile_fields'],
			['label', str_repeat('x', 201), 'profile_fields'],
			['method', 'unknown-crypt', 'unknown_method'],
			['key_id', false, 'profile_fields'],
			['keys', ['key-2026' => ['mode' => 'env']], 'keys_list'],
			['keys', ['invalid'], 'invalid_key_entry'],
			['keys', [['id' => 'key-2026', 'mode' => 'unknown']], 'unsupported_source'],
			['key_id', 'key-not-configured', 'active_key_missing'],
			['keys', [
				['id' => 'key-2026', 'mode' => 'env', 'name' => 'KEY_2026'],
				['id' => 'key-2026', 'mode' => 'env', 'name' => 'KEY_2026']
			], 'duplicate_key_id'],
			['keys', [['id' => 'key-2026', 'mode' => 'env', 'name' => '']], 'source_fields_required'],
			['keys', [['id' => 'key-2026', 'mode' => 'file', 'path' => '']], 'source_fields_required'],
			['keys', [['id' => 'key-2026', 'mode' => 'configuration', 'group' => 'x']], 'source_fields_required']
		];
		foreach ($cases as [$field, $value, $reason]) {
			$settings = new EncryptionTestSettingsStore();
			$service = $this->service($settings);
			$invalid = $base;
			$invalid[$field] = $value;
			try {
				$service->saveProfileForAdmin($invalid);
				self::fail('Invalid profile must not be saved: ' . $field);
			} catch (\KeyHarbor\Exception\EncryptionProfileValidationException $exception) {
				self::assertSame($reason, $exception->getReason(), $field);
			}
			self::assertSame([], $settings->getGroup('keyharbor_encryption_profiles'));
		}
	}

	public function testSavedEncryptionMethodCannotBeChanged(): void {
		$settings = new EncryptionTestSettingsStore();
		$service = $this->service($settings);
		$service->saveProfileForAdmin($this->profile());
		$modified = $this->profile();
		$modified['method'] = 'aes-256-cbc-legacy-v1';
		try {
			$service->saveProfileForAdmin($modified);
			self::fail('Existing encryption method must remain immutable.');
		} catch (\KeyHarbor\Exception\EncryptionProfileValidationException $exception) {
			self::assertSame('method_immutable', $exception->getReason());
		}
		self::assertSame('xchacha20poly1305-v1', $settings->get('keyharbor_encryption_profiles', 'profile-a')['method']);
	}

	public function testUnknownStoredEncryptionMethodCannotGenerateWriteKey(): void {
		$settings = new EncryptionTestSettingsStore();
		$settings->set('keyharbor_encryption_profiles', 'profile-a', [
			'method' => 'no-such-cipher', 'key_id' => 'key-a',
			'keys' => ['key-a' => ['mode' => 'env', 'name' => 'KEY']]
		]);
		$settings->set('keyharbor_encryption_targets', 'example:store', ['profile_id' => 'profile-a']);
		try {
			$this->service($settings)->getWriteKey('example:store');
			self::fail('Unknown encryption method must fail closed.');
		} catch (\KeyHarbor\Exception\EncryptionProfileValidationException $exception) {
			self::assertSame('unknown_method', $exception->getReason());
		}
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
		$config = $this->createStub(IConfiguration::class);
		$config->method('getValue')->willReturn($masterKeyConfigured ? base64_encode(str_repeat('m', 32)) : null);
		$resolver = $this->createStub(IConfigValueResolver::class);
		$resolver->method('resolve')->willReturn(str_repeat('e', 40));
		$cipher = new CredentialSecretCipher($config, $resolver);
		$classMap = new EncryptionTestClassMap([
			IEncryptionTargetProvider::class => [new EncryptionExampleTargetProvider()],
			ICrypt::class => [$this->method('xchacha20poly1305-v1'), $this->method('aes-256-cbc-legacy-v1')]
		]);
		$identity = $this->createStub(IUsermanager::class);
		$user = new User();
		$user->id = 10;
		$identity->method('getUser')->willReturn($user);
		$authorization = $this->createStub(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('test');
		$authorization->method('decide')->willReturn(AuthorizationDecision::allow());
		$management = new CredentialManagementService(
			$identity, $authorization, $this->createStub(ICredentialRepository::class),
			new CredentialTokenService(), new CredentialServiceCatalog($classMap), $cipher
		);
		return new EncryptionProfileService($store, $resolver, $classMap, $cipher, $management);
	}

	private function method(string $name): ICrypt {
		$crypt = $this->createStub(ICrypt::class);
		$crypt->method('getMethod')->willReturn($name);
		return $crypt;
	}
}

require_once __DIR__ . '/EncryptionExampleTargetProvider.php';

require_once __DIR__ . '/EncryptionTestSettingsStore.php';

require_once __DIR__ . '/EncryptionTestClassMap.php';
