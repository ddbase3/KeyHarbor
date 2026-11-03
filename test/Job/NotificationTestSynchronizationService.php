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
