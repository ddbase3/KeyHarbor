<?php declare(strict_types=1);

namespace KeyHarbor\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Logger\Api\ILogger;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use InvalidArgumentException;
use KeyHarbor\Exception\CredentialManagementException;
use KeyHarbor\Dto\IssuedCredential;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Service\CredentialManagementService;
use Throwable;

/**
 * Lets system administrators review, revoke and delete revoked credentials.
 */
class KeyHarborAdminDisplay implements IDisplay {

	public function __construct(
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly IAssetResolver $assetResolver,
		private readonly ILinkTargetService $linkTargetService,
		private readonly ILogger $logger,
		private readonly CredentialManagementService $managementService
	) {}

	public static function getName(): string {
		return 'keyharboradmindisplay';
	}

	/** Scope the dedicated technical-credentials view without duplicating its administration. */
	protected function isServiceOnly(): bool {
		return false;
	}

	public function setData($data) {
		// no-op
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if (strtolower($out) === 'json') {
			return $this->handleJson($final);
		}

		return $this->handleHtml();
	}

	public function getHelp(): string {
		$this->view->setPath(dirname(__DIR__, 2));
		$this->view->loadBricks('Display');
		$translations = $this->view->getBricks('keyharbor_admin_display');
		$translations = is_array($translations) ? $translations : [];
		$helpKey = $this->isServiceOnly() ? 'service_page_help' : 'help';
		$help = trim((string)($translations[$helpKey] ?? ''));
		if ($help === '') {
			throw new \UnexpectedValueException('Missing KeyHarbor translation: ' . $helpKey);
		}
		return $help;
	}

	private function handleHtml(): string {
		$this->view->setPath(dirname(__DIR__, 2));
		$this->view->loadBricks('Display');
		$translations = $this->view->getBricks('keyharbor_admin_display');
		$translations = is_array($translations) ? $translations : [];

		$error = '';
		try {
			$this->managementService->assertCurrentUserAdmin();
		} catch (CredentialManagementException $exception) {
			$error = trim((string)($translations['error_' . $exception->getReason()] ?? ''));
			if ($error === '') {
				$error = (string)($translations['request_failed'] ?? '');
			}
		} catch (Throwable $throwable) {
			$this->logFailure('KeyHarbor admin display failed.', $throwable);
			$error = trim((string)($translations['unavailable_error'] ?? ''));
			if ($error === '') {
				throw new \UnexpectedValueException('Missing KeyHarbor translation: unavailable_error');
			}
		}

		$this->view->setTemplate('Display/KeyHarborAdminDisplay.php');
		$this->view->assign('error', $error);
		$this->view->assign('translations', $translations);
		$this->view->assign('serviceOnly', $this->isServiceOnly());
		$this->view->assign(
			'service',
			$this->linkTargetService->getLink([
				'name' => static::getName(),
				'out' => 'json'
			])
		);
		$this->view->assign(
			'cssUrl',
			$this->assetResolver->resolve('plugin/KeyHarbor/assets/keyharbor/keyharbor.css')
		);
		$this->view->assign(
			'scriptUrl',
			$this->assetResolver->resolve('plugin/KeyHarbor/assets/keyharbor/keyharboradmin.js')
		);

		$this->view->assign('dialogCssUrl', $this->assetResolver->resolve('plugin/ClientStack/assets/modulardialog/styles/modulardialog.css'));
		$this->view->assign('dialogJsUrl', $this->assetResolver->resolve('plugin/ClientStack/assets/modulardialog/index.js'));

		return $this->view->loadTemplate();
	}

	private function handleJson(bool $final): string {
		$errorId = $this->createErrorId();

		try {
			$response = $this->buildJsonResponse();
		} catch (CredentialManagementException $exception) {
			$response = [
				'ok' => false,
				'error_code' => $exception->getReason(),
				'error' => $exception->getMessage()
			];
		} catch (InvalidArgumentException $exception) {
			$response = [
				'ok' => false,
				'error_code' => 'invalid_input',
				'error' => $exception->getMessage()
			];
		} catch (Throwable $throwable) {
			$this->logFailure('KeyHarbor admin request failed.', $throwable, [
				'error_id' => $errorId
			]);
			$response = [
				'ok' => false,
				'error_code' => 'internal_error',
				'error_id' => $errorId,
				'error' => 'KeyHarbor admin request failed. Reference: ' . $errorId
			];
		}

		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
		}

		return (string)json_encode(
			$response,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildJsonResponse(): array {
		$this->assertAjaxJsonRequest();
		$payload = $this->request->getJsonBody();
		$mode = $this->readString($payload, 'mode', 'list');

		if ($mode === 'list') {
			$credentials = $this->managementService->listAllForAdmin();
			if ($this->isServiceOnly()) {
				$credentials = array_values(array_filter($credentials,
					static fn(ApiCredential $credential): bool => $credential->isServiceCredential()
				));
			}
			return [
				'ok' => true,
				'services' => array_map(
					fn(CredentialServiceDefinition $service): array => $service->toArray(),
					$this->managementService->getAvailableServices()
				),
				'credentials' => array_map(
					fn(ApiCredential $credential): array => $this->credentialToArray($credential),
					$credentials
				)
			];
		}

		if ($mode === 'create_service') {
			$issued = $this->managementService->createForService(
				$this->readString($payload, 'subject_id'),
				$this->readString($payload, 'label'),
				$this->readString($payload, 'notification_address'),
				$this->readString($payload, 'notification_language'),
				$this->readNullableTimestamp($payload, 'expires_at'),
				$this->readOperationGrants($payload),
				$this->readResourceConstraints($payload)
			);
			return $this->issuedResponse('created', $issued);
		}

		if ($mode === 'update_service') {
			$credential = $this->managementService->updateServiceAsAdmin(
				$this->readString($payload, 'credential_id'),
				$this->readString($payload, 'label'),
				$this->readString($payload, 'notification_address'),
				$this->readString($payload, 'notification_language'),
				$this->readNullableTimestamp($payload, 'expires_at'),
				$this->readOperationGrants($payload),
				$this->readResourceConstraints($payload)
			);
			return [
				'ok' => true,
				'action' => 'updated',
				'credential' => $this->credentialToArray($credential)
			];
		}

		if ($mode === 'rotate_service') {
			return $this->issuedResponse('rotated', $this->managementService->rotateServiceAsAdmin(
				$this->readString($payload, 'credential_id')
			));
		}

		if ($mode === 'revoke') {
			$credential = $this->managementService->revokeAsAdmin(
				$this->readString($payload, 'credential_id')
			);
			return [
				'ok' => true,
				'action' => 'revoked',
				'credential' => $this->credentialToArray($credential)
			];
		}

		if ($mode === 'delete') {
			$credentialId = $this->readString($payload, 'credential_id');
			$this->managementService->deleteAsAdmin($credentialId);
			return [
				'ok' => true,
				'action' => 'deleted',
				'credential_id' => $credentialId
			];
		}

		throw new InvalidArgumentException('Unsupported KeyHarbor admin mode: ' . $mode);
	}

	/** @return array<string,mixed> One-time token response, never persisted in the browser. */
	private function issuedResponse(string $action, IssuedCredential $issued): array {
		return [
			'ok' => true,
			'action' => $action,
			'credential' => $this->credentialToArray($issued->getCredential()),
			'token' => $issued->getGeneratedToken()->getToken()
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function credentialToArray(ApiCredential $credential): array {
		$now = time();
		$status = 'active';
		if ($credential->isRevoked()) {
			$status = 'revoked';
		} elseif ($credential->isExpired($now)) {
			$status = 'expired';
		}

		return [
			'id' => $credential->getId(),
			'public_id' => $credential->getPublicId(),
			'label' => $credential->getLabel(),
			'subject_type' => $credential->getSubject()->getType(),
			'subject_id' => $credential->getSubject()->getId(),
			'operation_grants' => $credential->getOperationGrants(),
			'resource_constraints' => $credential->getResourceConstraints(),
			'owner_user_id' => $credential->getOwnerUserId(),
			'owner_login' => $credential->getOwnerLogin(),
			'owner_name' => $credential->getOwnerName(),
			'notification_address' => $credential->getNotificationAddress(),
			'notification_language' => $credential->getNotificationLanguage(),
			'auth_mode' => $credential->isHmacEnabled() ? 'hmac' : 'bearer',
			'created_at' => $credential->getCreatedAt(),
			'expires_at' => $credential->getExpiresAt(),
			'revoked_at' => $credential->getRevokedAt(),
			'warning_notified_at' => $credential->getWarningNotifiedAt(),
			'expiry_notified_at' => $credential->getExpiryNotifiedAt(),
			'status' => $status,
			'service_ids' => $credential->getServiceIds()
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function readString(array $payload, string $key, string $default = ''): string {
		$value = $payload[$key] ?? $default;
		return is_scalar($value) ? trim((string)$value) : $default;
	}

	/** @param array<string,mixed> $payload */
	private function readNullableTimestamp(array $payload, string $key): ?int {
		$value = $payload[$key] ?? null;
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
			throw new InvalidArgumentException('Credential expiration must be a Unix timestamp or null.');
		}
		return (int)$value;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,array<int,string>>
	 */
	/** @return array<string,array<string,array<string,mixed>>> */
	private function readResourceConstraints(array $payload): array {
		$raw = $payload['resource_constraints'] ?? [];
		if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
			throw new InvalidArgumentException('Resource constraints must be a keyed object.');
		}
		return $raw;
	}

	private function readOperationGrants(array $payload): array {
		$raw = $payload['operation_grants'] ?? null;
		if (!is_array($raw) || array_is_list($raw)) {
			throw new InvalidArgumentException('Service operation grants must be a keyed object.');
		}
		$grants = [];
		foreach ($raw as $serviceId => $operations) {
			if (!is_string($serviceId) || !is_array($operations) || !array_is_list($operations)) {
				throw new InvalidArgumentException('Service operation grants must be keyed lists.');
			}
			$grants[$serviceId] = $operations;
		}
		return $grants;
	}

	private function assertAjaxJsonRequest(): void {
		$method = strtoupper(trim((string)$this->request->server('REQUEST_METHOD', '')));
		$requestedWith = strtolower(trim((string)$this->request->server('HTTP_X_REQUESTED_WITH', '')));
		$contentType = strtolower(trim((string)$this->request->server('CONTENT_TYPE', '')));

		if ($method !== 'POST' || $requestedWith !== 'xmlhttprequest' ||
			!str_starts_with($contentType, 'application/json')) {
			throw new InvalidArgumentException(
				'KeyHarbor administration requests must use AJAX with a JSON POST body.'
			);
		}
	}

	private function createErrorId(): string {
		return bin2hex(random_bytes(6));
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function logFailure(string $message, Throwable $throwable, array $context = []): void {
		$errorId = trim((string)($context['error_id'] ?? ''));
		$parts = [rtrim($message)];
		if ($errorId !== '') {
			$parts[] = 'Reference: ' . $errorId;
		}
		$parts[] = $this->formatThrowable($throwable);

		$this->logger->error(implode(' ', $parts), [
			'scope' => 'keyharbor'
		]);
	}

	private function formatThrowable(Throwable $throwable): string {
		$parts = [];
		$current = $throwable;
		$depth = 0;

		while ($current !== null && $depth < 5) {
			$parts[] = $current::class . ': ' . $current->getMessage() .
				' @ ' . $current->getFile() . ':' . $current->getLine();
			$current = $current->getPrevious();
			$depth++;
		}

		return implode(' <- ', $parts);
	}
}
