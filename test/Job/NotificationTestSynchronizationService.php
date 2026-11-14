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

use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Api\ISystemService;
use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\LinkTarget\Api\ILinkTargetService;
use Base3\CoreFoundation\Logger\Api\ILogger;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\CoreFoundation\Worker\Api\IJobExecutionPolicy;
use Base3\CredentialFoundation\Api\ICredentialServiceProvider;
use Base3\CredentialFoundation\Dto\CredentialServiceDefinition;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\Job\KeyExpirationNotificationJob;
use Base3\KeyHarbor\Model\ApiCredential;
use Base3\KeyHarbor\Service\CredentialServiceCatalog;
use Base3\MessagingFoundation\Api\IMessageRenderer;
use Base3\MessagingFoundation\Api\IMessageService;
use Base3\MessagingFoundation\Api\IMessageTypeSynchronizationService;
use Base3\MessagingFoundation\Dto\Message;
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
