<?php declare(strict_types=1);

namespace KeyHarbor\Test\Job;

use Base3\Api\IClassMap;
use Base3\Api\ISystemService;
use Base3\Configuration\Api\IConfiguration;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Logger\Api\ILogger;
use Base3\State\Api\IStateStore;
use Base3\Worker\Api\IJobExecutionPolicy;
use CredentialFoundation\Api\ICredentialServiceProvider;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Job\KeyExpirationNotificationJob;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Service\CredentialServiceCatalog;
use MessagingFoundation\Api\IMessageRenderer;
use MessagingFoundation\Api\IMessageService;
use MessagingFoundation\Api\IMessageTypeSynchronizationService;
use MessagingFoundation\Dto\Message;
use PHPUnit\Framework\TestCase;

final class KeyExpirationNotificationJobTest extends TestCase {

	public function testQueuesNotificationsAndMarksPolicyRun(): void {
		$repository = new NotificationTestRepository(
			[$this->credential('1', 'en', 'expired@example.test')],
			[$this->credential('2', 'de', 'warning@example.test')]
		);
		$renderer = new NotificationTestRenderer();
		$messageService = new NotificationTestMessageService();
		$sync = new NotificationTestSynchronizationService();
		$state = new NotificationTestStateStore();
		$logger = new NotificationTestLogger();
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository, $renderer, $messageService, $sync, $state, $logger);
		$job->setExecutionPolicy($policy);

		$result = $job->go();

		self::assertStringContainsString('queued: 2', $result);
		self::assertCount(2, $messageService->messages);
		self::assertSame(['keyharborexpired', 'keyharborexpiring'], array_column($renderer->renders, 'type'));
		self::assertSame(['en', 'de'], array_column($renderer->renders, 'language'));
		self::assertSame([str_repeat('1', 32)], array_keys($repository->expiryMarks));
		self::assertSame([str_repeat('2', 32)], array_keys($repository->warningMarks));
		self::assertSame(1, $policy->markCount);
		self::assertFalse($state->has('locks.keyharbor.expiration-notification'));
		self::assertCount(4, $sync->calls);
		self::assertCount(0, $logger->warnings);
	}

	public function testFailureDoesNotStopOtherCredentialsOrSetFailedMarker(): void {
		$repository = new NotificationTestRepository([], [
			$this->credential('3', 'en', ''),
			$this->credential('4', 'en', 'valid@example.test')
		]);
		$renderer = new NotificationTestRenderer();
		$messageService = new NotificationTestMessageService();
		$sync = new NotificationTestSynchronizationService();
		$state = new NotificationTestStateStore();
		$logger = new NotificationTestLogger();
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository, $renderer, $messageService, $sync, $state, $logger);
		$job->setExecutionPolicy($policy);

		$result = $job->go();

		self::assertStringContainsString('queued: 1; failed: 1', $result);
		self::assertCount(1, $messageService->messages);
		self::assertArrayNotHasKey(str_repeat('3', 32), $repository->warningMarks);
		self::assertArrayHasKey(str_repeat('4', 32), $repository->warningMarks);
		self::assertCount(1, $logger->warnings);
		self::assertSame(1, $policy->markCount);
	}

	public function testHeldLockSkipsWithoutMarkingPolicyRun(): void {
		$repository = new NotificationTestRepository([], []);
		$state = new NotificationTestStateStore(false);
		$policy = new NotificationTestPolicy();
		$job = $this->job(
			$repository,
			new NotificationTestRenderer(),
			new NotificationTestMessageService(),
			new NotificationTestSynchronizationService(),
			$state,
			new NotificationTestLogger()
		);
		$job->setExecutionPolicy($policy);

		self::assertStringContainsString('already running', $job->go());
		self::assertSame(0, $policy->markCount);
		self::assertSame(0, $repository->findCalls);
	}

	private function job(
		NotificationTestRepository $repository,
		NotificationTestRenderer $renderer,
		NotificationTestMessageService $messageService,
		NotificationTestSynchronizationService $sync,
		NotificationTestStateStore $state,
		NotificationTestLogger $logger
	): KeyExpirationNotificationJob {
		$catalog = new CredentialServiceCatalog(new NotificationTestClassMap([
			new NotificationTestCredentialServiceProvider()
		]));

		return new KeyExpirationNotificationJob(
			$repository,
			$catalog,
			$sync,
			$renderer,
			$messageService,
			$state,
			new NotificationTestConfiguration(),
			new NotificationTestSystemService(),
			new NotificationTestLinkTargetService(),
			$logger
		);
	}

	private function credential(string $digit, string $language, string $address): ApiCredential {
		return new ApiCredential(
			str_repeat($digit, 32),
			str_repeat($digit, 20),
			'42',
			'demo',
			'<b>Demo User</b>',
			$address,
			$language,
			'<script>Credential</script> ' . $digit,
			str_repeat('a', 64),
			false,
			null,
			null,
			time() - 3600,
			time() + 3600,
			null,
			null,
			null,
			['example:read']
		);
	}
}

final class NotificationTestRepository implements ICredentialRepository {

	/** @var array<int,ApiCredential> */
	private array $expired;
	/** @var array<int,ApiCredential> */
	private array $expiring;
	/** @var array<string,int> */
	public array $warningMarks = [];
	/** @var array<string,int> */
	public array $expiryMarks = [];
	public int $findCalls = 0;

	/**
	 * @param array<int,ApiCredential> $expired
	 * @param array<int,ApiCredential> $expiring
	 */
	public function __construct(array $expired, array $expiring) {
		$this->expired = $expired;
		$this->expiring = $expiring;
	}

	public function insert(ApiCredential $credential): void {}
	public function getById(string $id): ?ApiCredential { return null; }
	public function getByPublicId(string $publicId): ?ApiCredential { return null; }
	public function getByOwner(string $id, int|string $ownerUserId): ?ApiCredential { return null; }
	public function listByOwner(int|string $ownerUserId): array { return []; }
	public function listAll(): array { return []; }
	public function update(ApiCredential $credential): bool { return false; }
	public function updateForOwner(ApiCredential $credential, int|string $ownerUserId): bool { return false; }

	public function deleteRevoked(string $id): bool { return false; }
	public function deleteRevokedForOwner(string $id, int|string $ownerUserId): bool { return false; }

	public function findExpiring(int $fromExclusive, int $toInclusive): array {
		$this->findCalls++;
		return $this->expiring;
	}

	public function findExpired(int $now): array {
		$this->findCalls++;
		return $this->expired;
	}

	public function markWarningNotified(string $id, int $notifiedAt): bool {
		$this->warningMarks[$id] = $notifiedAt;
		return true;
	}

	public function markExpiryNotified(string $id, int $notifiedAt): bool {
		$this->expiryMarks[$id] = $notifiedAt;
		return true;
	}
}

final class NotificationTestRenderer implements IMessageRenderer {

	/** @var array<int,array{type:string,language:string,context:array<string,mixed>}> */
	public array $renders = [];

	public function render(string $typeName, string $language, array $context = [], string $transportName = ''): Message {
		$this->renders[] = [
			'type' => $typeName,
			'language' => $language,
			'context' => $context
		];
		return new Message($typeName, 'Subject', 'Body');
	}
}

final class NotificationTestMessageService implements IMessageService {

	/** @var array<int,Message> */
	public array $messages = [];

	public function enqueue(Message $message, string $transportName = '', int $priority = 100, ?int $notBefore = null): string {
		$this->messages[] = $message;
		return 'queue-' . count($this->messages);
	}

	public function sendNow(Message $message, string $transportName = ''): string {
		$this->messages[] = $message;
		return 'sent-' . count($this->messages);
	}
}

final class NotificationTestSynchronizationService implements IMessageTypeSynchronizationService {

	/** @var array<int,array{type:string,language:string}> */
	public array $calls = [];

	public function syncAll(string $language = 'en'): array {
		return [];
	}

	public function syncOne(string $typeName, string $language = 'en'): array {
		$this->calls[] = ['type' => $typeName, 'language' => $language];
		return ['ok' => true];
	}

	public function getProviderSummaries(): array {
		return [];
	}
}

final class NotificationTestStateStore implements IStateStore {

	/** @var array<string,mixed> */
	private array $values = [];

	public function __construct(
		private readonly bool $allowLock = true
	) {}

	public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
	public function has(string $key): bool { return array_key_exists($key, $this->values); }
	public function set(string $key, mixed $value, ?int $ttlSeconds = null): void { $this->values[$key] = $value; }
	public function delete(string $key): bool {
		$exists = $this->has($key);
		unset($this->values[$key]);
		return $exists;
	}
	public function setIfNotExists(string $key, mixed $value, ?int $ttlSeconds = null): bool {
		if (!$this->allowLock || $this->has($key)) {
			return false;
		}
		$this->values[$key] = $value;
		return true;
	}
	public function listKeys(string $prefix): array { return []; }
	public function flush(): void {}
}

final class NotificationTestConfiguration implements IConfiguration {

	public function get($configuration = '') { return $configuration === 'job' ? ['keyexpirationnotificationjob.active' => 1] : []; }
	public function set($data, $configuration = ''): void {}
	public function save(): void {}
	public function getGroup(string $group, array $default = []): array { return $default; }
	public function getValue(string $group, string $key, $default = null) { return $default; }
	public function getString(string $group, string $key, string $default = ''): string { return $default; }
	public function getInt(string $group, string $key, int $default = 0): int { return $default; }
	public function getFloat(string $group, string $key, float $default = 0.0): float { return $default; }
	public function getBool(string $group, string $key, bool $default = false): bool { return $default; }
	public function getArray(string $group, string $key, array $default = []): array { return $default; }
	public function hasGroup(string $group): bool { return false; }
	public function hasValue(string $group, string $key): bool { return false; }
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

final class NotificationTestSystemService implements ISystemService {
	public function getHostSystemName(): string { return 'ILIAS'; }
	public function getHostSystemVersion(): string { return ''; }
	public function getEmbeddedSystemName(): string { return 'BASE3'; }
	public function getEmbeddedSystemVersion(): string { return ''; }
}

final class NotificationTestLinkTargetService implements ILinkTargetService {
	public function getLink(array $target, array $params = []): string { return '/keymanagementdisplay.html'; }
}

final class NotificationTestLogger implements ILogger {

	/** @var array<int,array<string,mixed>> */
	public array $warnings = [];

	public function emergency(string|\Stringable $message, array $context = []): void {}
	public function alert(string|\Stringable $message, array $context = []): void {}
	public function critical(string|\Stringable $message, array $context = []): void {}
	public function error(string|\Stringable $message, array $context = []): void {}
	public function warning(string|\Stringable $message, array $context = []): void { $this->warnings[] = $context; }
	public function notice(string|\Stringable $message, array $context = []): void {}
	public function info(string|\Stringable $message, array $context = []): void {}
	public function debug(string|\Stringable $message, array $context = []): void {}
	public function logLevel(string $level, string|\Stringable $message, array $context = []): void {}
	public function log(string $scope, string $log, ?int $timestamp = null): bool { return true; }
	public function getScopes(): array { return []; }
	public function getNumOfScopes() { return 0; }
	public function getLogs(string $scope, int $num = 50, bool $reverse = true): array { return []; }
}

final class NotificationTestPolicy implements IJobExecutionPolicy {

	public int $markCount = 0;

	public static function getName(): string { return 'notificationtestpolicy'; }
	public function setData(array $data): void {}
	public function shouldRun(string $jobName): bool { return true; }
	public function markRun(string $jobName): void { $this->markCount++; }
	public function getReason(): string { return ''; }
	public function getSchema(): array { return []; }
}

final class NotificationTestCredentialServiceProvider implements ICredentialServiceProvider {
	public static function getName(): string { return 'notificationtestcredentialserviceprovider'; }
	public function getServices(): array { return [new CredentialServiceDefinition('example:read', '<b>Example Read</b>')]; }
}

final class NotificationTestClassMap implements IClassMap {

	/** @var array<int,object> */
	private array $instances;
	/** @var array<int,object> */
	private array $empty = [];

	/** @param array<int,object> $instances */
	public function __construct(array $instances) { $this->instances = $instances; }
	public function instantiate(string $class) { return null; }
	public function instantiateWith(string $class, array $arguments = []) { return null; }
	public function generate($regenerate = false): void {}
	public function getApps() { return []; }
	public function &getInstances(array $criteria = []) { return $this->empty; }
	public function &getInstancesByInterface($interface) { return $this->instances; }
	public function &getInstancesByAppInterface($app, $interface, $retry = false) { return $this->empty; }
	public function &getInstanceByAppName($app, $name, $retry = false) { $result = null; return $result; }
	public function getClassByInterfaceName(string $interface, string $name): ?string { return null; }
	public function &getInstanceByInterfaceName($interface, $name, $retry = false) { $result = null; return $result; }
	public function &getInstanceByAppInterfaceName($app, $interface, $name, $retry = false) { $result = null; return $result; }
	public function getPlugins() { return []; }
}
