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
