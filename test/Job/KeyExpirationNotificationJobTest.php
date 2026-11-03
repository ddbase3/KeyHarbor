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

require_once __DIR__ . '/NotificationTestRepository.php';

require_once __DIR__ . '/NotificationTestRenderer.php';

require_once __DIR__ . '/NotificationTestMessageService.php';

require_once __DIR__ . '/NotificationTestSynchronizationService.php';

require_once __DIR__ . '/NotificationTestStateStore.php';

require_once __DIR__ . '/NotificationTestConfiguration.php';

require_once __DIR__ . '/NotificationTestSystemService.php';

require_once __DIR__ . '/NotificationTestLinkTargetService.php';

require_once __DIR__ . '/NotificationTestLogger.php';

require_once __DIR__ . '/NotificationTestPolicy.php';

require_once __DIR__ . '/NotificationTestCredentialServiceProvider.php';

require_once __DIR__ . '/NotificationTestClassMap.php';
