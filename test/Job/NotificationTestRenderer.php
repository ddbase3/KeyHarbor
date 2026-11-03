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
