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

final class NotificationTestPolicy implements IJobExecutionPolicy {

	public int $markCount = 0;

	public static function getName(): string { return 'notificationtestpolicy'; }
	public function setData(array $data): void {}
	public function shouldRun(string $jobName): bool { return true; }
	public function markRun(string $jobName): void { $this->markCount++; }
	public function getReason(): string { return ''; }
	public function getSchema(): array { return []; }
}
