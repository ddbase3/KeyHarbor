<?php declare(strict_types=1);

namespace KeyHarbor\Test\Job;

use Base3\Api\ISystemService;
use Base3\LinkTarget\Api\ILinkTargetService;
use CredentialFoundation\Dto\CredentialSubject;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Job\KeyExpirationNotificationJob;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Service\CredentialServiceCatalog;
use MessagingFoundation\Api\IMessageService;
use MessagingFoundation\Api\IMessageTypeSynchronizationService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KeyExpirationNotificationRecoveryTest extends TestCase {

	public function testFailureLoadingExpiredCredentialsReleasesLockWithoutMarkingJobComplete(): void {
		$repository = $this->createStub(ICredentialRepository::class);
		$repository->method('findExpired')->willReturnCallback(static function(): never {
			throw new RuntimeException('database unavailable');
		});
		$state = new NotificationTestStateStore();
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository, state: $state);
		$job->setExecutionPolicy($policy);

		try {
			$job->go();
			self::fail('A failed repository read must not be mistaken for an empty run.');
		} catch (RuntimeException $exception) {
			self::assertSame('database unavailable', $exception->getMessage());
		}
		self::assertFalse($state->has('locks.keyharbor.expiration-notification'));
		self::assertSame(0, $policy->markCount);
	}

	public function testFailureLoadingExpiringCredentialsReleasesLockWithoutMarkingJobComplete(): void {
		$repository = $this->createStub(ICredentialRepository::class);
		$repository->method('findExpired')->willReturn([]);
		$repository->method('findExpiring')->willReturnCallback(static function(): never {
			throw new RuntimeException('expiring credential query unavailable');
		});
		$state = new NotificationTestStateStore();
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository, state: $state);
		$job->setExecutionPolicy($policy);

		try {
			$job->go();
			self::fail('The second repository query must not be silently skipped.');
		} catch (RuntimeException $exception) {
			self::assertSame('expiring credential query unavailable', $exception->getMessage());
		}
		self::assertFalse($state->has('locks.keyharbor.expiration-notification'));
		self::assertSame(0, $policy->markCount);
	}

	public function testMessageTypeSynchronizationFailureStopsQueueingAndReleasesLock(): void {
		$credential = $this->credential('a', time() + 3600);
		$repository = new NotificationTestRepository([], [$credential]);
		$sync = $this->createStub(IMessageTypeSynchronizationService::class);
		$sync->method('syncOne')->willReturnCallback(static function(): never {
			throw new RuntimeException('message catalog unavailable');
		});
		$messages = new NotificationTestMessageService();
		$state = new NotificationTestStateStore();
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository, sync: $sync, messages: $messages, state: $state);
		$job->setExecutionPolicy($policy);

		try {
			$job->go();
			self::fail('Message type synchronization errors must be visible to the worker.');
		} catch (RuntimeException $exception) {
			self::assertSame('message catalog unavailable', $exception->getMessage());
		}
		self::assertSame([], $messages->messages);
		self::assertSame([], $repository->warningMarks);
		self::assertSame(0, $policy->markCount);
		self::assertFalse($state->has('locks.keyharbor.expiration-notification'));
	}

	public function testQueueTransportFailureDoesNotMarkNotificationAsDelivered(): void {
		$credential = $this->credential('b', time() + 3600);
		$repository = new NotificationTestRepository([], [$credential]);
		$messages = $this->createStub(IMessageService::class);
		$messages->method('enqueue')->willReturnCallback(static function(): never {
			throw new RuntimeException('queue transport unavailable');
		});
		$logger = new NotificationTestLogger();
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository, messages: $messages, logger: $logger);
		$job->setExecutionPolicy($policy);

		self::assertStringContainsString('queued: 0; failed: 1', $job->go());
		self::assertSame([], $repository->warningMarks);
		self::assertSame(1, $policy->markCount);
		self::assertCount(1, $logger->warnings);
		self::assertSame('queue transport unavailable', $logger->warnings[0]['error']);
		self::assertSame($credential->getId(), $logger->warnings[0]['credential_id']);
		self::assertSame('expiring', $logger->warnings[0]['notification_kind']);
	}

	public function testInvalidExpiringRepositoryItemIsRejectedBeforeSynchronization(): void {
		$repository = $this->createStub(ICredentialRepository::class);
		$repository->method('findExpired')->willReturn([]);
		$repository->method('findExpiring')->willReturn(['invalid-row']);
		$sync = new NotificationTestSynchronizationService();
		$state = new NotificationTestStateStore();
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository, sync: $sync, state: $state);
		$job->setExecutionPolicy($policy);

		try {
			$job->go();
			self::fail('Malformed expiry results must not initiate notification handling.');
		} catch (RuntimeException $exception) {
			self::assertStringContainsString('invalid expiration result', $exception->getMessage());
		}
		self::assertSame([], $sync->calls);
		self::assertSame(0, $policy->markCount);
		self::assertFalse($state->has('locks.keyharbor.expiration-notification'));
	}

	public function testExpiringWindowIsExactlySevenDaysAndStartsAtCurrentTime(): void {
		$windows = [];
		$repository = $this->createStub(ICredentialRepository::class);
		$repository->method('findExpired')->willReturn([]);
		$repository->method('findExpiring')->willReturnCallback(static function(int $start, int $end) use (&$windows): array {
			$windows[] = [$start, $end];
			return [];
		});
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository);
		$job->setExecutionPolicy($policy);
		$before = time();

		self::assertStringContainsString('queued: 0; failed: 0', $job->go());
		self::assertCount(1, $windows);
		self::assertTrue($windows[0][0] >= $before);
		self::assertTrue($windows[0][0] <= time());
		self::assertSame(604800, $windows[0][1] - $windows[0][0]);
		self::assertSame(1, $policy->markCount);
	}

	public function testContextRemovesHtmlMarkupAndControlCharactersBeforeRendering(): void {
		$credential = new ApiCredential(
			str_repeat('c', 32), str_repeat('c', 20), '42', 'owner', '<b>Alex</b>' . "\r\n" . ' Smith',
			'notify@example.test', 'en', '<i>Service</i>' . "\0\n" . ' key', str_repeat('a', 64),
			false, null, null, time() - 60, time() + 3600, null, null, null, ['missing:service']
		);
		$renderer = new NotificationTestRenderer();
		$job = $this->job(new NotificationTestRepository([], [$credential]), renderer: $renderer);
		$job->setExecutionPolicy(new NotificationTestPolicy());

		self::assertStringContainsString('queued: 1', $job->go());
		self::assertSame('Alex Smith', $renderer->renders[0]['context']['user_name']);
		self::assertSame('Service key', $renderer->renders[0]['context']['key_label']);
		self::assertSame('missing:service', $renderer->renders[0]['context']['service_labels']);
	}

	private function job(
		ICredentialRepository $repository,
		?IMessageTypeSynchronizationService $sync = null,
		?IMessageService $messages = null,
		?NotificationTestStateStore $state = null,
		?NotificationTestLogger $logger = null,
		?NotificationTestRenderer $renderer = null
	): KeyExpirationNotificationJob {
		return new KeyExpirationNotificationJob(
			$repository,
			new CredentialServiceCatalog(new NotificationTestClassMap([new NotificationTestCredentialServiceProvider()])),
			$sync ?? new NotificationTestSynchronizationService(),
			$renderer ?? new NotificationTestRenderer(),
			$messages ?? new NotificationTestMessageService(),
			$state ?? new NotificationTestStateStore(),
			new NotificationTestConfiguration(),
			new NotificationTestSystemService(),
			new NotificationTestLinkTargetService(),
			$logger ?? new NotificationTestLogger()
		);
	}

	private function credential(string $digit, ?int $expiresAt): ApiCredential {
		return new ApiCredential(
			str_repeat($digit, 32), str_repeat($digit, 20), '42', 'owner', 'Owner',
			'notify@example.test', 'en', 'Credential ' . $digit, str_repeat('a', 64),
			false, null, null, time() - 60, $expiresAt, null, null, null, ['example:read']
		);
	}
}

require_once __DIR__ . '/NotificationTestRepository.php';
require_once __DIR__ . '/NotificationTestClassMap.php';
require_once __DIR__ . '/NotificationTestCredentialServiceProvider.php';
require_once __DIR__ . '/NotificationTestSynchronizationService.php';
require_once __DIR__ . '/NotificationTestRenderer.php';
require_once __DIR__ . '/NotificationTestMessageService.php';
require_once __DIR__ . '/NotificationTestStateStore.php';
require_once __DIR__ . '/NotificationTestPolicy.php';
require_once __DIR__ . '/NotificationTestConfiguration.php';
require_once __DIR__ . '/NotificationTestSystemService.php';
require_once __DIR__ . '/NotificationTestLinkTargetService.php';
require_once __DIR__ . '/NotificationTestLogger.php';
