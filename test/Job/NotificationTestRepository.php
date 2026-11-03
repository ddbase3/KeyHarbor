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
