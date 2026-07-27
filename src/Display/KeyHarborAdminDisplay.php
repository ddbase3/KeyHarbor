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
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Service\CredentialManagementService;
use Throwable;

/**
 * Lets system administrators review, revoke and delete revoked credentials.
 */
final class KeyHarborAdminDisplay implements IDisplay {

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
		return 'Review, revoke and delete revoked KeyHarbor credentials as a system administrator.';
	}

	private function handleHtml(): string {
		$error = '';
		try {
			$this->managementService->assertCurrentUserAdmin();
		} catch (CredentialManagementException $exception) {
			$error = $exception->getMessage();
		} catch (Throwable $throwable) {
			$this->logFailure('KeyHarbor admin display failed.', $throwable);
			$error = 'KeyHarbor administration is currently unavailable.';
		}

		$this->view->setPath(DIR_PLUGIN . 'KeyHarbor');
		$this->view->setTemplate('Display/KeyHarborAdminDisplay.php');
		$this->view->assign('error', $error);
		$this->view->assign(
			'service',
			$this->linkTargetService->getLink([
				'name' => self::getName(),
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
