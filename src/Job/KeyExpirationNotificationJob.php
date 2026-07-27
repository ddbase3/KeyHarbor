<?php declare(strict_types=1);

namespace KeyHarbor\Job;

use Base3\Api\ISystemService;
use Base3\Configuration\Api\IConfiguration;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Logger\Api\ILogger;
use Base3\State\Api\IStateStore;
use Base3\Worker\Api\IPolicyControlledJob;
use Base3\Worker\Policy\PolicyControlledJobTrait;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Display\KeyManagementDisplay;
use KeyHarbor\Message\KeyExpiredMessageTypeProvider;
use KeyHarbor\Message\KeyExpiringMessageTypeProvider;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Service\CredentialServiceCatalog;
use MessagingFoundation\Api\IMessageRenderer;
use MessagingFoundation\Api\IMessageService;
use MessagingFoundation\Api\IMessageTypeSynchronizationService;
use MessagingFoundation\Dto\MessageAddress;
use RuntimeException;
use Throwable;

/**
 * Queues credential expiry warnings and expiry notifications through MessageHub.
 */
final class KeyExpirationNotificationJob implements IPolicyControlledJob {

	use PolicyControlledJobTrait;

	private const LOCK_KEY = 'locks.keyharbor.expiration-notification';
	private const LOCK_TTL_SECONDS = 900;
	private const WARNING_WINDOW_SECONDS = 604800;
	private const DEFAULT_PRIORITY = 1;

	private ?array $jobConfiguration = null;

	public function __construct(
		private readonly ICredentialRepository $repository,
		private readonly CredentialServiceCatalog $serviceCatalog,
		private readonly IMessageTypeSynchronizationService $synchronizationService,
		private readonly IMessageRenderer $messageRenderer,
		private readonly IMessageService $messageService,
		private readonly IStateStore $stateStore,
		private readonly IConfiguration $configuration,
		private readonly ISystemService $systemService,
		private readonly ILinkTargetService $linkTargetService,
		private readonly ILogger $logger
	) {}

	public static function getName(): string {
		return 'keyexpirationnotificationjob';
	}

	public function isActive(): bool {
		$configuration = $this->getJobConfiguration();
		return ((int)($configuration[self::getName() . '.active'] ?? 0)) === 1;
	}

	public function getPriority(): int {
		$configuration = $this->getJobConfiguration();
		return (int)($configuration[self::getName() . '.priority'] ?? self::DEFAULT_PRIORITY);
	}

	public function getPolicyDefinition(): array {
		return [
			'policy' => 'intervaljobpolicy',
			'data' => [
				'seconds' => 3600,
				'id' => 'keyharbor-expiration'
			]
		];
	}

	public function go(): string {
		if (!$this->stateStore->setIfNotExists(self::LOCK_KEY, time(), self::LOCK_TTL_SECONDS)) {
			return 'Skip (KeyHarbor expiration notification job already running)';
		}

		try {
			$now = time();
			$expired = $this->repository->findExpired($now);
			$expiring = $this->repository->findExpiring($now, $now + self::WARNING_WINDOW_SECONDS);
			$this->synchronizeMessageTypes($expired, $expiring);

			$queued = 0;
			$failed = 0;

			foreach ($expired as $credential) {
				if ($this->queueNotification($credential, true, $now)) {
					$queued++;
				} else {
					$failed++;
				}
			}

			foreach ($expiring as $credential) {
				if ($this->queueNotification($credential, false, $now)) {
					$queued++;
				} else {
					$failed++;
				}
			}

			$this->markRun();

			return 'KeyHarbor expiration notifications queued: ' . $queued . '; failed: ' . $failed . '.';
		} finally {
			$this->stateStore->delete(self::LOCK_KEY);
		}
	}

	/**
	 * @param array<int,ApiCredential> $expired
	 * @param array<int,ApiCredential> $expiring
	 */
	private function synchronizeMessageTypes(array $expired, array $expiring): void {
		$languages = [];
		foreach (array_merge($expired, $expiring) as $credential) {
			if (!$credential instanceof ApiCredential) {
				throw new RuntimeException('Credential repository returned an invalid expiration result.');
			}
			$languages[$this->normalizeLanguage($credential->getNotificationLanguage())] = true;
		}

		foreach (array_keys($languages) as $language) {
			$this->synchronizationService->syncOne(KeyExpiringMessageTypeProvider::getName(), $language);
			$this->synchronizationService->syncOne(KeyExpiredMessageTypeProvider::getName(), $language);
		}
	}

	private function queueNotification(ApiCredential $credential, bool $expired, int $notifiedAt): bool {
		try {
			$address = trim($credential->getNotificationAddress());
			if ($address === '') {
				throw new RuntimeException('Credential has no notification address.');
			}
			if ($credential->getExpiresAt() === null) {
				throw new RuntimeException('Credential has no expiration timestamp.');
			}

			$typeName = $expired
				? KeyExpiredMessageTypeProvider::getName()
				: KeyExpiringMessageTypeProvider::getName();
			$language = $this->normalizeLanguage($credential->getNotificationLanguage());
			$context = $this->createContext($credential);
			$message = $this->messageRenderer
				->render($typeName, $language, $context)
				->withRecipients([
					new MessageAddress('to', $address, $credential->getOwnerName())
				])
				->withMetadata([
					'keyharbor_credential_id' => $credential->getId(),
					'keyharbor_public_id' => $credential->getPublicId(),
					'keyharbor_owner_user_id' => $credential->getOwnerUserId(),
					'keyharbor_notification_kind' => $expired ? 'expired' : 'expiring'
				]);

			$this->messageService->enqueue($message, '', 100, $notifiedAt);
			$marked = $expired
				? $this->repository->markExpiryNotified($credential->getId(), $notifiedAt)
				: $this->repository->markWarningNotified($credential->getId(), $notifiedAt);

			if (!$marked) {
				throw new RuntimeException('Credential notification timestamp could not be marked.');
			}

			return true;
		} catch (Throwable $throwable) {
			$this->logger->warning('KeyHarbor credential notification could not be queued.', [
				'scope' => 'keyharbor',
				'credential_id' => $credential->getId(),
				'notification_kind' => $expired ? 'expired' : 'expiring',
				'error' => $throwable->getMessage(),
				'exception' => get_class($throwable)
			]);

			return false;
		}
	}

	/**
	 * @return array<string,string>
	 */
	private function createContext(ApiCredential $credential): array {
		$expiresAt = $credential->getExpiresAt();
		if ($expiresAt === null) {
			throw new RuntimeException('Credential has no expiration timestamp.');
		}

		return [
			'user_name' => $this->plainText($credential->getOwnerName()),
			'key_label' => $this->plainText($credential->getLabel()),
			'key_public_id' => $credential->getPublicId(),
			'expires_at' => date('Y-m-d H:i:s T', $expiresAt),
			'service_labels' => $this->getServiceLabels($credential),
			'system_name' => $this->getSystemName(),
			'manage_url' => $this->linkTargetService->getLink([
				'name' => KeyManagementDisplay::getName(),
				'out' => 'html'
			])
		];
	}

	private function getServiceLabels(ApiCredential $credential): string {
		$labels = [];
		foreach ($credential->getServiceIds() as $serviceId) {
			$service = $this->serviceCatalog->getService($serviceId);
			$labels[] = $this->plainText($service !== null ? $service->getLabel() : $serviceId);
		}

		return implode(', ', $labels);
	}

	private function getSystemName(): string {
		$host = $this->plainText($this->systemService->getHostSystemName());
		$embedded = $this->plainText($this->systemService->getEmbeddedSystemName());

		if ($host !== '' && $embedded !== '' && strcasecmp($host, $embedded) !== 0) {
			return $host . ' / ' . $embedded;
		}
		if ($host !== '') {
			return $host;
		}
		if ($embedded !== '') {
			return $embedded;
		}

		return 'BASE3';
	}

	private function normalizeLanguage(string $language): string {
		$language = strtolower(trim($language));
		return $language === '' ? 'en' : substr($language, 0, 12);
	}

	private function plainText(string $value): string {
		$value = strip_tags($value);
		$value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
		return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
	}

	private function getJobConfiguration(): array {
		if ($this->jobConfiguration === null) {
			$this->jobConfiguration = (array)$this->configuration->get('job');
		}

		return $this->jobConfiguration;
	}
}
