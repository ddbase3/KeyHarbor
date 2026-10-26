<?php declare(strict_types=1);

namespace KeyHarbor\Service;

use Base3\Api\IClassMap;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\Crypt\Api\ICrypt;
use Base3\Settings\Api\ISettingsStore;
use CredentialFoundation\Api\IEncryptionProfileService;
use CredentialFoundation\Api\IEncryptionTargetProvider;
use CredentialFoundation\Dto\EncryptionKeyMaterial;
use CredentialFoundation\Dto\EncryptionTargetDefinition;
use InvalidArgumentException;
use KeyHarbor\Security\CredentialSecretCipher;
use RuntimeException;
use UnexpectedValueException;

/**
 * Owns the single SettingsStore representation of managed encryption profiles and target assignments.
 * API credentials remain managed by the existing credential services.
 */
final class EncryptionProfileService implements IEncryptionProfileService {

	private const PROFILE_GROUP = 'keyharbor_encryption_profiles';
	private const TARGET_GROUP = 'keyharbor_encryption_targets';
	private const ID_PATTERN = '/^[a-z0-9][a-z0-9._:-]{0,127}$/D';

	public function __construct(
		private readonly ISettingsStore $settings,
		private readonly IConfigValueResolver $resolver,
		private readonly IClassMap $classMap,
		private readonly CredentialSecretCipher $secretCipher,
		private readonly CredentialManagementService $management
	) {}

	public function getAssignedProfileId(string $targetId): ?string {
		$this->assertId($targetId);
		$data = $this->settings->get(self::TARGET_GROUP, $targetId);
		if ($data === []) {
			return null;
		}
		$profileId = $data['profile_id'] ?? null;
		if (!is_string($profileId) || !preg_match(self::ID_PATTERN, $profileId)) {
			throw new RuntimeException('Invalid stored encryption assignment for target: ' . $targetId);
		}
		return $profileId;
	}

	public function getWriteKey(string $targetId): ?EncryptionKeyMaterial {
		$profileId = $this->getAssignedProfileId($targetId);
		if ($profileId === null) {
			return null;
		}
		$profile = $this->requireProfile($profileId);
		$keyId = $profile['key_id'] ?? null;
		$method = $profile['method'] ?? null;
		if (!is_string($keyId) || $keyId === '' || !is_string($method) || $method === '') {
			throw new RuntimeException('Assigned encryption profile lacks an active key or method.');
		}
		$this->requireMethod($method);
		return new EncryptionKeyMaterial($method, $keyId, $this->resolveStoredKey($profile, $keyId));
	}

	public function getReadKey(string $method, string $keyId): string {
		$this->assertId($keyId);
		$matched = null;
		foreach ($this->profiles() as $profileId => $profile) {
			if (!is_array($profile) || !isset($profile['keys'][$keyId])) {
				continue;
			}
			if ($matched !== null) {
				throw new RuntimeException('Duplicate historical encryption key ID: ' . $keyId);
			}
			$matched = $profile;
		}
		if ($matched === null || ($matched['method'] ?? null) !== $method) {
			throw new RuntimeException('Historical encryption key is unavailable for the recorded method.');
		}
		$this->requireMethod($method);
		return $this->resolveStoredKey($matched, $keyId);
	}

	/** @return string[] */
	public function getMethodsForAdmin(): array {
		$this->management->assertCurrentUserAdmin();
		$methods = array_keys($this->getMethodMap());
		sort($methods, SORT_STRING);
		return $methods;
	}

	/** @return array<int,array<string,mixed>> */
	public function listProfilesForAdmin(): array {
		$this->management->assertCurrentUserAdmin();
		$profiles = [];
		foreach ($this->profiles() as $id => $data) {
			if (!is_array($data)) {
				throw new UnexpectedValueException('Invalid encryption profile record.');
			}
			$keys = [];
			foreach ($data['keys'] ?? [] as $keyId => $definition) {
				if (!is_array($definition)) {
					throw new UnexpectedValueException('Invalid encryption key source record.');
				}
				$public = ['id' => (string)$keyId, 'mode' => (string)($definition['mode'] ?? '')];
				foreach (['name', 'path', 'group', 'key'] as $field) {
					if (isset($definition[$field])) {
						$public[$field] = (string)$definition[$field];
					}
				}
				$keys[] = $public;
			}
			$profiles[] = [
				'id' => (string)$id,
				'label' => (string)($data['label'] ?? ''),
				'method' => (string)($data['method'] ?? ''),
				'key_id' => (string)($data['key_id'] ?? ''),
				'keys' => $keys
			];
		}
		usort($profiles, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
		return $profiles;
	}

	/** @return EncryptionTargetDefinition[] */
	public function listTargetsForAdmin(): array {
		$this->management->assertCurrentUserAdmin();
		$targets = [];
		foreach ($this->classMap->getInstancesByInterface(IEncryptionTargetProvider::class) as $provider) {
			if (!$provider instanceof IEncryptionTargetProvider) {
				throw new UnexpectedValueException('Invalid encryption target provider.');
			}
			foreach ($provider->getTargets() as $target) {
				if (!$target instanceof EncryptionTargetDefinition) {
					throw new UnexpectedValueException('Invalid encryption target definition.');
				}
				$id = $target->getTargetId();
				if (isset($targets[$id])) {
					throw new RuntimeException('Duplicate encryption target ID: ' . $id);
				}
				$targets[$id] = $target;
			}
		}
		ksort($targets, SORT_STRING);
		return array_values($targets);
	}

	/** @return array<string,string> */
	public function listAssignmentsForAdmin(): array {
		$this->management->assertCurrentUserAdmin();
		$assignments = [];
		foreach ($this->settings->getGroup(self::TARGET_GROUP) as $id => $value) {
			if (!is_array($value) || !is_string($value['profile_id'] ?? null)) {
				throw new UnexpectedValueException('Invalid stored encryption target assignment.');
			}
			$assignments[(string)$id] = $value['profile_id'];
		}
		ksort($assignments, SORT_STRING);
		return $assignments;
	}

	/**
	 * Saves profile metadata and additional keys without ever changing or removing
	 * persisted key definitions. Fixed secrets remain hidden from the editor.
	 *
	 * @param array<string,mixed> $input Complete editor record
	 * @return void
	 * @throws InvalidArgumentException If stored keys are missing or modified, or input is invalid
	 * @throws RuntimeException If the settings store cannot persist the profile
	 * @sideeffect Persists the updated profile in ISettingsStore
	 */
	public function saveProfileForAdmin(array $input): void {
		$this->management->assertCurrentUserAdmin();
		$id = $this->assertId($input['id'] ?? null);
		$label = trim((string)($input['label'] ?? ''));
		$method = $input['method'] ?? null;
		$activeId = $input['key_id'] ?? null;
		if ($label === '' || strlen($label) > 200 || !is_string($method) || !is_string($activeId)) {
			throw new InvalidArgumentException('Profile label, method and active key ID are required.');
		}
		$this->requireMethod($method);
		$activeId = $this->assertId($activeId);
		$old = $this->settings->get(self::PROFILE_GROUP, $id);
		if ($old !== [] && ($old['method'] ?? null) !== $method) {
			throw new InvalidArgumentException('The encryption method of an existing profile cannot be changed. Create another profile.');
		}
		$incoming = $input['keys'] ?? null;
		if (!is_array($incoming) || !array_is_list($incoming)) {
			throw new InvalidArgumentException('Encryption keys must be a list.');
		}
		if ($old !== [] && !is_array($old['keys'] ?? null)) {
			throw new RuntimeException('Invalid stored encryption keys.');
		}
		$previousKeys = $old['keys'] ?? [];
		$keys = [];
		foreach ($incoming as $entry) {
			if (!is_array($entry)) {
				throw new InvalidArgumentException('Invalid key entry.');
			}
			$keyId = $this->assertId($entry['id'] ?? null);
			if (isset($keys[$keyId])) {
				throw new InvalidArgumentException('Duplicate key ID within the profile.');
			}
			$mode = $entry['mode'] ?? null;
			if (!is_string($mode) || !in_array($mode, ['fixed', 'env', 'file', 'configuration'], true)) {
				throw new InvalidArgumentException('Unsupported encryption key source.');
			}
			$previous = $previousKeys[$keyId] ?? null;
			if ($previous !== null) {
				if (!is_array($previous) || ($previous['mode'] ?? null) !== $mode) {
					throw new InvalidArgumentException('Persisted encryption keys are immutable.');
				}
				if ($mode === 'fixed') {
					if (($entry['secret'] ?? '') !== '') {
						throw new InvalidArgumentException('Persisted encryption secrets cannot be replaced. Rotate the key.');
					}
				} else {
					$fields = $mode === 'env' ? ['name'] : ($mode === 'file' ? ['path'] : ['group', 'key']);
					foreach ($fields as $field) {
						if (!is_string($entry[$field] ?? null) || trim($entry[$field]) !== ($previous[$field] ?? null)) {
							throw new InvalidArgumentException('Persisted encryption key sources cannot be changed.');
						}
					}
				}
				$keys[$keyId] = $previous;
				continue;
			}
			if ($mode === 'fixed') {
				$secret = $entry['secret'] ?? null;
				if (!is_string($secret) || strlen($secret) < 32) {
					throw new InvalidArgumentException('New fixed keys require at least 32 bytes of random secret material.');
				}
				$encrypted = $this->secretCipher->encrypt($secret);
				$keys[$keyId] = [
					'mode' => 'fixed',
					'ciphertext' => $encrypted->getCiphertext(),
					'nonce' => $encrypted->getNonce()
				];
			} else {
				$keys[$keyId] = ['mode' => $mode];
				$fields = $mode === 'env' ? ['name'] : ($mode === 'file' ? ['path'] : ['group', 'key']);
				foreach ($fields as $field) {
						$value = $entry[$field] ?? null;
					if (!is_string($value) || trim($value) === '') {
							throw new InvalidArgumentException('Encryption key source is missing a required field.');
						}
					$keys[$keyId][$field] = trim($value);
				}
			}
		}
		if (!isset($keys[$activeId])) {
			throw new InvalidArgumentException('The active key ID must reference a configured key.');
		}
		foreach ($this->profiles() as $otherId => $otherProfile) {
			if ((string)$otherId === $id) {
				continue;
			}
			foreach (array_keys($keys) as $keyId) {
				if (array_key_exists($keyId, (array)($otherProfile['keys'] ?? []))) {
					throw new InvalidArgumentException('Encryption key IDs must be globally unique.');
				}
			}
		}
		foreach (array_keys($previousKeys) as $previousId) {
			if (!array_key_exists($previousId, $keys)) {
				throw new InvalidArgumentException('Persisted encryption keys cannot be removed.');
			}
		}
		$this->settings->set(self::PROFILE_GROUP, $id, [
			'label' => $label,
			'method' => $method,
			'key_id' => $activeId,
			'keys' => $keys
		]);
		$this->settings->save();
	}

	/**
	 * Rotates a fixed key by adding a new random secret and switching the active ID.
	 * All historical keys and the profile identity remain unchanged.
	 *
	 * @param string $id Existing profile ID
	 * @return string Newly activated key ID
	 * @throws InvalidArgumentException If the active key is not fixed
	 * @throws RuntimeException If the profile is invalid or persistence fails
	 * @sideeffect Persists a new encrypted fixed secret and the active key ID
	 */
	public function rotateFixedKeyForAdmin(string $id): string {
		$this->management->assertCurrentUserAdmin();
		$profile = $this->requireProfile($this->assertId($id));
		$active = $profile['key_id'] ?? null;
		if (!is_string($active) || ($profile['keys'][$active]['mode'] ?? null) !== 'fixed') {
			throw new InvalidArgumentException('Only profiles with an active fixed key support automatic rotation.');
		}
		$newId = 'key-' . bin2hex(random_bytes(12));
		foreach ($this->profiles() as $existingProfile) {
			if (array_key_exists($newId, (array)($existingProfile['keys'] ?? []))) {
				throw new RuntimeException('Generated encryption key ID already exists.');
			}
		}
		$secret = base64_encode(random_bytes(32));
		$encrypted = $this->secretCipher->encrypt($secret);
		$profile['keys'][$newId] = [
			'mode' => 'fixed',
			'ciphertext' => $encrypted->getCiphertext(),
			'nonce' => $encrypted->getNonce()
		];
		$profile['key_id'] = $newId;
		$this->settings->set(self::PROFILE_GROUP, $id, $profile);
		$this->settings->save();
		return $newId;
	}

	public function assignForAdmin(string $targetId, ?string $profileId, bool $confirmChange = false): void {
		$this->management->assertCurrentUserAdmin();
		$this->assertId($targetId);
		$previous = $this->getAssignedProfileId($targetId);
		if ($previous !== null && $previous !== ($profileId ?? '') && !$confirmChange) {
			throw new InvalidArgumentException('Changing an existing encryption assignment requires explicit confirmation.');
		}
		if ($profileId !== null && $profileId !== '') {
			$this->assertId($profileId);
			$exists = false;
			foreach ($this->listTargetsForAdmin() as $target) {
				if ($target->getTargetId() === $targetId) {
					$exists = true;
					break;
				}
			}
			if (!$exists) {
				throw new InvalidArgumentException('Encryption target is not currently available.');
			}
			$profile = $this->requireProfile($profileId);
			$this->requireMethod((string)($profile['method'] ?? ''));
			$this->resolveStoredKey($profile, (string)($profile['key_id'] ?? ''));
			$this->settings->set(self::TARGET_GROUP, $targetId, ['profile_id' => $profileId]);
		} else {
			$this->settings->remove(self::TARGET_GROUP, $targetId);
		}
		$this->settings->save();
	}

	/** @return array<string,array<string,mixed>> */
	private function profiles(): array {
		return $this->settings->getGroup(self::PROFILE_GROUP);
	}

	/** @return array<string,mixed> */
	private function requireProfile(string $id): array {
		$profile = $this->settings->get(self::PROFILE_GROUP, $id);
		if ($profile === []) {
			throw new RuntimeException('Encryption profile is missing: ' . $id);
		}
		if (!is_array($profile['keys'] ?? null)) {
			throw new RuntimeException('Invalid stored encryption keys.');
		}
		return $profile;
	}

	/** @param array<string,mixed> $profile */
	private function resolveStoredKey(array $profile, string $keyId): string {
		$definition = $profile['keys'][$keyId] ?? null;
		if (!is_array($definition)) {
			throw new RuntimeException('Encryption key not available: ' . $keyId);
		}
		if (($definition['mode'] ?? null) === 'fixed') {
			$secret = $this->secretCipher->decrypt((string)($definition['ciphertext'] ?? ''), (string)($definition['nonce'] ?? ''));
		} else {
			$mode = $definition['mode'] ?? null;
			if (!in_array($mode, ['env', 'file', 'configuration'], true)) {
				throw new RuntimeException('Unsupported stored encryption key source.');
			}
			$secret = $this->resolver->resolve($definition);
		}
		if (!is_string($secret) || $secret === '') {
			throw new RuntimeException('Encryption key source did not resolve to a secret.');
		}
		return $secret;
	}

	/** @return array<string,ICrypt> */
	private function getMethodMap(): array {
		$methods = [];
		foreach ($this->classMap->getInstancesByInterface(ICrypt::class) as $candidate) {
			if (!$candidate instanceof ICrypt) {
				throw new UnexpectedValueException('Invalid framework encryption implementation.');
			}
			$method = $candidate->getMethod();
			if (isset($methods[$method])) {
				throw new RuntimeException('Duplicate framework encryption method: ' . $method);
			}
			$methods[$method] = $candidate;
		}
		return $methods;
	}

	private function requireMethod(string $method): void {
		if ($method === '' || !isset($this->getMethodMap()[$method])) {
			throw new InvalidArgumentException('Unknown encryption method.');
		}
	}

	private function assertId(mixed $id): string {
		if (!is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
			throw new InvalidArgumentException('Invalid encryption identifier.');
		}
		return $id;
	}
}
