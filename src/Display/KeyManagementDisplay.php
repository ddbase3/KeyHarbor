<?php declare(strict_types=1);

namespace KeyHarbor\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Logger\Api\ILogger;
use Base3\Usermanager\User;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use InvalidArgumentException;
use KeyHarbor\Dto\IssuedCredential;
use KeyHarbor\Exception\CredentialHmacConfigurationException;
use KeyHarbor\Exception\CredentialManagementException;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Service\CredentialManagementService;
use Throwable;

/**
 * Lets the current user manage only their own API credentials.
 */
final class KeyManagementDisplay implements IDisplay {

	public function __construct(
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly IAssetResolver $assetResolver,
		private readonly ILinkTargetService $linkTargetService,
		private readonly ILogger $logger,
		private readonly CredentialManagementService $managementService
	) {}

	public static function getName(): string {
		return 'keymanagementdisplay';
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
		return 'Manage API credentials owned by the current user.';
	}

	private function handleHtml(): string {
		$this->view->setPath(DIR_PLUGIN . 'KeyHarbor');
		$this->view->loadBricks('Display');
		$translations = $this->view->getBricks('keyharbor_management_display');
		$translations = is_array($translations) ? $translations : [];

		$error = '';
		$profile = [];
		$canAdmin = false;

		try {
			$user = $this->managementService->getCurrentUser();
			$canAdmin = $this->managementService->isCurrentUserAdmin();
			$profile = $this->userToArray($user, $canAdmin);
		} catch (CredentialManagementException $exception) {
			$error = $exception->getMessage();
		} catch (Throwable $throwable) {
			$this->logFailure('KeyHarbor user display failed.', $throwable);
			$error = trim((string)($translations['unavailable_error'] ?? ''));
			if ($error === '') {
				$error = 'Credential management is currently unavailable.';
			}
		}

		$this->view->setTemplate('Display/KeyManagementDisplay.php');
		$this->view->assign('error', $error);
		$this->view->assign('profile', $profile);
		$this->view->assign('translations', $translations);
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
			$this->assetResolver->resolve('plugin/KeyHarbor/assets/keyharbor/keymanagement.js')
		);

		return $this->view->loadTemplate();
	}

	private function handleJson(bool $final): string {
		$errorId = $this->createErrorId();

		try {
			$response = $this->buildJsonResponse();
		} catch (CredentialHmacConfigurationException $exception) {
			$response = [
				'ok' => false,
				'error_code' => 'hmac_configuration',
				'error' => $exception->getMessage()
			];
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
			$this->logFailure('KeyHarbor user request failed.', $throwable, [
				'error_id' => $errorId
			]);
			$response = [
				'ok' => false,
				'error_code' => 'internal_error',
				'error_id' => $errorId,
				'error' => 'Credential request failed. Reference: ' . $errorId
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
			$user = $this->managementService->getCurrentUser();
			return [
				'ok' => true,
				'profile' => $this->userToArray(
					$user,
					$this->managementService->isCurrentUserAdmin()
				),
				'services' => array_map(
					fn(CredentialServiceDefinition $service): array => $service->toArray(),
					$this->managementService->getAvailableServices()
				),
				'credentials' => array_map(
					fn(ApiCredential $credential): array => $this->credentialToArray($credential),
					$this->managementService->listForCurrentUser()
				)
			];
		}

		if ($mode === 'create') {
			$issued = $this->managementService->createForCurrentUser(
				$this->readString($payload, 'label'),
				$this->readString($payload, 'notification_address'),
				$this->readString($payload, 'notification_language'),
				$this->readNullableTimestamp($payload, 'expires_at'),
				$this->readServiceIds($payload),
				$this->readString($payload, 'authentication_mode', 'bearer')
			);
			return $this->issuedResponse('created', $issued);
		}

		if ($mode === 'update') {
			$credential = $this->managementService->updateForCurrentUser(
				$this->readString($payload, 'credential_id'),
				$this->readString($payload, 'label'),
				$this->readString($payload, 'notification_address'),
				$this->readString($payload, 'notification_language'),
				$this->readNullableTimestamp($payload, 'expires_at'),
				$this->readServiceIds($payload)
			);
			return [
				'ok' => true,
				'action' => 'updated',
				'credential' => $this->credentialToArray($credential)
			];
		}

		if ($mode === 'rotate') {
			$issued = $this->managementService->rotateForCurrentUser(
				$this->readString($payload, 'credential_id')
			);
			return $this->issuedResponse('rotated', $issued);
		}

		if ($mode === 'revoke') {
			$credential = $this->managementService->revokeForCurrentUser(
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
			$this->managementService->deleteForCurrentUser($credentialId);
			return [
				'ok' => true,
				'action' => 'deleted',
				'credential_id' => $credentialId
			];
		}

		throw new InvalidArgumentException('Unsupported credential management mode: ' . $mode);
	}

	/**
	 * @return array<string,mixed>
	 */
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
			'token_prefix' => 'b3k_' . $credential->getPublicId() . '_',
			'label' => $credential->getLabel(),
			'notification_address' => $credential->getNotificationAddress(),
			'notification_language' => $credential->getNotificationLanguage(),
			'auth_mode' => $credential->isHmacEnabled() ? 'hmac' : 'bearer',
			'created_at' => $credential->getCreatedAt(),
			'expires_at' => $credential->getExpiresAt(),
			'revoked_at' => $credential->getRevokedAt(),
			'status' => $status,
			'service_ids' => $credential->getServiceIds()
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function userToArray(User $user, bool $admin): array {
		return [
			'id' => (string)$user->id,
			'login' => (string)($user->userid ?? ''),
			'name' => (string)($user->name ?? ''),
			'email' => (string)($user->email ?? ''),
			'language' => (string)($user->lang ?? ''),
			'admin' => $admin
		];
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function readString(array $payload, string $key, string $default = ''): string {
		$value = $payload[$key] ?? $default;
		return is_scalar($value) ? trim((string)$value) : $default;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
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
	 * @return array<int,mixed>
	 */
	private function readServiceIds(array $payload): array {
		$serviceIds = $payload['service_ids'] ?? [];
		if (!is_array($serviceIds)) {
			throw new InvalidArgumentException('Credential service ids must be an array.');
		}
		return array_values($serviceIds);
	}

	private function assertAjaxJsonRequest(): void {
		$method = strtoupper(trim((string)$this->request->server('REQUEST_METHOD', '')));
		$requestedWith = strtolower(trim((string)$this->request->server('HTTP_X_REQUESTED_WITH', '')));
		$contentType = strtolower(trim((string)$this->request->server('CONTENT_TYPE', '')));

		if ($method !== 'POST' || $requestedWith !== 'xmlhttprequest' ||
			!str_starts_with($contentType, 'application/json')) {
			throw new InvalidArgumentException(
				'Credential management requests must use AJAX with a JSON POST body.'
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
