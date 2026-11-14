<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of KeyHarbor for BASE3 Framework.
 *
 * KeyHarbor manages durable API credentials for BASE3 applications.
 * It provides secure credential lifecycle and encryption profiles.
 * Authorization and credential subjects follow existing shared contracts.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 * https://github.com/ddbase3/KeyHarbor
 **********************************************************************/

namespace Base3\KeyHarbor\Test\Job;

use Base3\CoreFoundation\Api\ISystemService;
use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\LinkTarget\Api\ILinkTargetService;
use Base3\CredentialFoundation\Dto\CredentialSubject;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\Display\KeyHarborAdminDisplay;
use Base3\KeyHarbor\Job\KeyExpirationNotificationJob;
use Base3\KeyHarbor\Model\ApiCredential;
use Base3\KeyHarbor\Service\CredentialServiceCatalog;
use Base3\MessagingFoundation\Api\IMessageRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KeyExpirationNotificationEdgeCasesTest extends TestCase {

	public function testJobConfigurationIsLoadedOnceForActivationAndPriority(): void {
		$reads = 0;
		$config = $this->createStub(IConfiguration::class);
		$config->method('get')->willReturnCallback(static function(string $section) use (&$reads): array {
			self::assertSame('job', $section);
			$reads++;
			return [
				'keyexpirationnotificationjob.active' => '1',
				'keyexpirationnotificationjob.priority' => '7'
			];
		});
		$job = $this->job(new NotificationTestRepository([], []), configuration: $config);

		self::assertTrue($job->isActive());
		self::assertTrue($job->isActive());
		self::assertSame(7, $job->getPriority());
		self::assertSame(1, $reads);
	}

	public function testAbsentJobConfigurationDoesNotActivateJob(): void {
		$config = $this->createStub(IConfiguration::class);
		$config->method('get')->willReturn([]);
		$job = $this->job(new NotificationTestRepository([], []), configuration: $config);

		self::assertFalse($job->isActive());
		self::assertSame(1, $job->getPriority());
	}

	public function testExpiredNotificationPreservesRecipientAndSecurityMetadata(): void {
		$credential = $this->credential('a', time() - 10);
		$messages = new NotificationTestMessageService();
		$repository = new NotificationTestRepository([$credential], []);
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository, messages: $messages);
		$job->setExecutionPolicy($policy);

		self::assertStringContainsString('queued: 1; failed: 0', $job->go());
		self::assertCount(1, $messages->messages);
		$message = $messages->messages[0];
		self::assertSame('keyharborexpired', $message->getTypeName());
		self::assertSame([
			'keyharbor_credential_id' => $credential->getId(),
			'keyharbor_public_id' => $credential->getPublicId(),
			'keyharbor_owner_user_id' => '42',
			'keyharbor_notification_kind' => 'expired'
		], $message->getMetadata());
		self::assertSame('to', $message->getRecipients()[0]->getType());
		self::assertSame('notify@example.test', $message->getRecipients()[0]->getAddress());
		self::assertSame('Owner Name', $message->getRecipients()[0]->getName());
		self::assertSame(1, $policy->markCount);
	}

	public function testUnmarkedNotificationIsReportedAsFailureAfterQueueing(): void {
		$credential = $this->credential('b', time() + 60);
		$repository = $this->createStub(ICredentialRepository::class);
		$repository->method('findExpired')->willReturn([]);
		$repository->method('findExpiring')->willReturn([$credential]);
		$repository->method('markWarningNotified')->willReturn(false);
		$messages = new NotificationTestMessageService();
		$logger = new NotificationTestLogger();
		$state = new NotificationTestStateStore();
		$policy = new NotificationTestPolicy();
		$job = $this->job($repository, messages: $messages, state: $state, logger: $logger);
		$job->setExecutionPolicy($policy);

		self::assertStringContainsString('queued: 0; failed: 1', $job->go());
		self::assertCount(1, $messages->messages);
		self::assertCount(1, $logger->warnings);
		self::assertSame('expiring', $logger->warnings[0]['notification_kind']);
		self::assertStringContainsString('could not be marked', $logger->warnings[0]['error']);
		self::assertSame(1, $policy->markCount);
		self::assertFalse($state->has('locks.keyharbor.expiration-notification'));
	}

	public function testRendererFailureDoesNotMarkCredentialAsNotified(): void {
		$credential = $this->credential('c', time() + 60);
		$repository = new NotificationTestRepository([], [$credential]);
		$renderer = $this->createStub(IMessageRenderer::class);
		$renderer->method('render')->willReturnCallback(static function(): never {
			throw new RuntimeException('Template rendering unavailable.');
		});
		$messages = new NotificationTestMessageService();
		$logger = new NotificationTestLogger();
		$job = $this->job($repository, renderer: $renderer, messages: $messages, logger: $logger);
		$job->setExecutionPolicy(new NotificationTestPolicy());

		self::assertStringContainsString('queued: 0; failed: 1', $job->go());
		self::assertSame([], $messages->messages);
		self::assertSame([], $repository->warningMarks);
		self::assertSame('RuntimeException', $logger->warnings[0]['exception']);
		self::assertSame('Template rendering unavailable.', $logger->warnings[0]['error']);
	}

	public function testCredentialWithoutExpirationCannotProduceNotification(): void {
		$credential = $this->credential('d', null);
		$repository = new NotificationTestRepository([], [$credential]);
		$messages = new NotificationTestMessageService();
		$logger = new NotificationTestLogger();
		$job = $this->job($repository, messages: $messages, logger: $logger);
		$job->setExecutionPolicy(new NotificationTestPolicy());

		self::assertStringContainsString('queued: 0; failed: 1', $job->go());
		self::assertSame([], $messages->messages);
		self::assertSame([], $repository->warningMarks);
		self::assertStringContainsString('expiration timestamp', $logger->warnings[0]['error']);
	}

	public function testSystemNameSupportsIdenticalHostAndEmbeddedNamesAndMissingNames(): void {
		$cases = [
			['ILIAS', 'BASE3', 'ILIAS / BASE3'],
			['ILIAS', 'ilias', 'ILIAS'],
			['ILIAS', '', 'ILIAS'],
			['', 'BASE3', 'BASE3'],
			['', '', 'BASE3']
		];
		foreach ($cases as [$host, $embedded, $expected]) {
			$system = $this->createStub(ISystemService::class);
			$system->method('getHostSystemName')->willReturn($host);
			$system->method('getEmbeddedSystemName')->willReturn($embedded);
			$renderer = new NotificationTestRenderer();
			$job = $this->job(
				new NotificationTestRepository([], [$this->credential('e', time() + 60)]),
				renderer: $renderer,
				system: $system
			);
			$job->setExecutionPolicy(new NotificationTestPolicy());
			$job->go();
			self::assertSame($expected, $renderer->renders[0]['context']['system_name']);
		}
	}

	public function testUnknownServiceLabelsAndLongLanguagesRemainBounded(): void {
		$credential = $this->credential('f', time() + 60, ' Very-Long-Language-Identifier ', ['unknown:service']);
		$renderer = new NotificationTestRenderer();
		$sync = new NotificationTestSynchronizationService();
		$job = $this->job(
			new NotificationTestRepository([], [$credential]),
			renderer: $renderer,
			sync: $sync
		);
		$job->setExecutionPolicy(new NotificationTestPolicy());
		self::assertStringContainsString('queued: 1', $job->go());
		self::assertSame('unknown:service', $renderer->renders[0]['context']['service_labels']);
		self::assertSame('very-long-la', $renderer->renders[0]['language']);
		self::assertSame(['very-long-la', 'very-long-la'], array_column($sync->calls, 'language'));
	}

	public function testTechnicalCredentialUsesAdministrativeManagementLink(): void {
		$credential = new ApiCredential(
			str_repeat('1', 32), str_repeat('1', 20), '42', 'owner', 'Owner Name',
			'notify@example.test', 'en', 'Technical key', str_repeat('a', 64),
			true, 'ciphertext', 'nonce', time() - 60, time() + 3600,
			null, null, null, ['example:read'],
			new CredentialSubject(CredentialSubject::TYPE_SERVICE, 'automation'),
			['example:read' => ['getEntries']]
		);
		$linkTarget = $this->createStub(ILinkTargetService::class);
		$requested = [];
		$linkTarget->method('getLink')->willReturnCallback(static function(array $target) use (&$requested): string {
			$requested[] = $target;
			return '/admin-credentials';
		});
		$renderer = new NotificationTestRenderer();
		$job = $this->job(
			new NotificationTestRepository([], [$credential]),
			renderer: $renderer,
			links: $linkTarget
		);
		$job->setExecutionPolicy(new NotificationTestPolicy());
		self::assertStringContainsString('queued: 1', $job->go());
		self::assertSame([['name' => KeyHarborAdminDisplay::getName(), 'out' => 'html']], $requested);
		self::assertSame('/admin-credentials', $renderer->renders[0]['context']['manage_url']);
	}

	private function job(
		ICredentialRepository $repository,
		?IMessageRenderer $renderer = null,
		?NotificationTestMessageService $messages = null,
		?NotificationTestSynchronizationService $sync = null,
		?NotificationTestStateStore $state = null,
		?NotificationTestLogger $logger = null,
		?IConfiguration $configuration = null,
		?ISystemService $system = null,
		?ILinkTargetService $links = null
	): KeyExpirationNotificationJob {
		return new KeyExpirationNotificationJob(
			$repository,
			new CredentialServiceCatalog(new NotificationTestClassMap([new NotificationTestCredentialServiceProvider()])),
			$sync ?? new NotificationTestSynchronizationService(),
			$renderer ?? new NotificationTestRenderer(),
			$messages ?? new NotificationTestMessageService(),
			$state ?? new NotificationTestStateStore(),
			$configuration ?? new NotificationTestConfiguration(),
			$system ?? new NotificationTestSystemService(),
			$links ?? new NotificationTestLinkTargetService(),
			$logger ?? new NotificationTestLogger()
		);
	}

	private function credential(string $digit, ?int $expiresAt, string $language = 'en', array $services = ['example:read']): ApiCredential {
		return new ApiCredential(
			str_repeat($digit, 32), str_repeat($digit, 20), '42', 'owner', 'Owner Name',
			'notify@example.test', $language, 'Credential ' . $digit,
			str_repeat('a', 64), false, null, null, time() - 60, $expiresAt,
			null, null, null, $services
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
