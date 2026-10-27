<?php declare(strict_types=1);

namespace KeyHarbor\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Logger\Api\ILogger;
use CredentialFoundation\Dto\EncryptionTargetDefinition;
use InvalidArgumentException;
use KeyHarbor\Exception\CredentialHmacConfigurationException;
use KeyHarbor\Exception\CredentialManagementException;
use KeyHarbor\Exception\EncryptionProfileValidationException;
use KeyHarbor\Service\CredentialManagementService;
use KeyHarbor\Service\EncryptionProfileService;
use Throwable;

/** One standalone BASE3 administration view for encryption profiles and their consumers. */
final class EncryptionProfilesDisplay implements IDisplay {

	public function __construct(
		private readonly IRequest $request,
		private readonly IMvcView $view,
		private readonly IAssetResolver $assets,
		private readonly ILinkTargetService $links,
		private readonly ILogger $logger,
		private readonly CredentialManagementService $management,
		private readonly EncryptionProfileService $encryption
	) {}

	public static function getName(): string {
		return 'encryptionprofilesdisplay';
	}

	public function setData($data) {}

	public function getOutput(string $out = 'html', bool $final = false): string {
		if (strtolower($out) === 'json') {
			return $this->handleJson($final);
		}
		return $this->handleHtml();
	}

	public function getHelp(): string {
		$this->view->setPath(dirname(__DIR__, 2));
		$this->view->loadBricks('Display');
		$translations = $this->view->getBricks('keyharbor_encryption_display');
		return (string)($translations['help'] ?? '');
	}

	private function handleHtml(): string {
		$this->view->setPath(dirname(__DIR__, 2));
		$this->view->loadBricks('Display');
		$translations = $this->view->getBricks('keyharbor_encryption_display');
		$translations = is_array($translations) ? $translations : [];
		$errorCode = '';
		try {
			$this->management->assertCurrentUserAdmin();
		} catch (CredentialManagementException $exception) {
			$errorCode = $exception->getReason();
		} catch (Throwable $exception) {
			$this->logger->error('KeyHarbor encryption administration unavailable: ' . $exception->getMessage(), ['scope' => 'keyharbor']);
			$errorCode = 'unavailable';
		}

		$this->view->setTemplate('Display/EncryptionProfilesDisplay.php');
		$this->view->assign('errorCode', $errorCode);
		$this->view->assign('translations', $translations);
		$this->view->assign('service', $this->links->getLink(['name' => self::getName(), 'out' => 'json']));
		$this->view->assign('cssUrl', $this->assets->resolve('plugin/KeyHarbor/assets/keyharbor/keyharbor.css'));
		$this->view->assign('scriptUrl', $this->assets->resolve('plugin/KeyHarbor/assets/keyharbor/encryptionprofiles.js'));
		$this->view->assign('dialogCssUrl', $this->assets->resolve('plugin/ClientStack/assets/modulardialog/styles/modulardialog.css'));
		$this->view->assign('dialogJsUrl', $this->assets->resolve('plugin/ClientStack/assets/modulardialog/index.js'));
		return $this->view->loadTemplate();
	}

	private function handleJson(bool $final): string {
		try {
			$this->management->assertCurrentUserAdmin();
			$this->assertAjaxJsonRequest();
			$body = $this->request->getJsonBody();
			$mode = $this->readString($body, 'mode');
			if ($mode === 'list') {
				$response = [
					'ok' => true,
					'profiles' => $this->encryption->listProfilesForAdmin(),
					'methods' => $this->encryption->getMethodsForAdmin(),
					'targets' => array_map(
						static fn(EncryptionTargetDefinition $target): array => $target->toArray(),
						$this->encryption->listTargetsForAdmin()
					),
					'assignments' => $this->encryption->listAssignmentsForAdmin()
				];
			} elseif ($mode === 'save') {
				$data = $body['profile'] ?? null;
				if (!is_array($data)) {
					throw new InvalidArgumentException('Missing profile.');
				}
				$this->encryption->saveProfileForAdmin($data);
				$response = ['ok' => true];
			} elseif ($mode === 'rotate') {
				$keyId = $this->encryption->rotateFixedKeyForAdmin($this->readString($body, 'profile_id'));
				$response = ['ok' => true, 'key_id' => $keyId];
			} elseif ($mode === 'assign') {
				$this->encryption->assignForAdmin(
					$this->readString($body, 'target_id'),
					$this->readString($body, 'profile_id'),
					($body['confirm_change'] ?? false) === true
				);
				$response = ['ok' => true];
			} else {
				throw new InvalidArgumentException('Unsupported KeyHarbor encryption administration operation.');
			}
		} catch (CredentialHmacConfigurationException $exception) {
			$this->logger->warning('KeyHarbor encryption master key configuration unavailable: ' . $exception->getMessage(), [
				'scope' => 'keyharbor'
			]);
			$response = ['ok' => false, 'error_code' => 'hmac_configuration'];
		} catch (CredentialManagementException $exception) {
			$response = ['ok' => false, 'error_code' => $exception->getReason()];
		} catch (EncryptionProfileValidationException $exception) {
			$response = ['ok' => false, 'error_code' => 'validation', 'validation_reason' => $exception->getReason()];
		} catch (InvalidArgumentException $exception) {
			$response = ['ok' => false, 'error_code' => 'validation'];
		} catch (Throwable $exception) {
			$errorId = bin2hex(random_bytes(6));
			$this->logger->error('KeyHarbor encryption administration request failed: ' . $exception->getMessage(), [
				'scope' => 'keyharbor', 'error_id' => $errorId
			]);
			$response = ['ok' => false, 'error_code' => 'internal_error', 'error_id' => $errorId];
		}
		if ($final && !headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
		}
		return (string)json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/** @param array<string,mixed> $body */
	private function readString(array $body, string $key): string {
		$value = $body[$key] ?? '';
		return is_string($value) ? trim($value) : '';
	}

	private function assertAjaxJsonRequest(): void {
		$method = strtoupper(trim((string)$this->request->server('REQUEST_METHOD', '')));
		$requestedWith = strtolower(trim((string)$this->request->server('HTTP_X_REQUESTED_WITH', '')));
		$contentType = strtolower(trim((string)$this->request->server('CONTENT_TYPE', '')));
		if ($method !== 'POST' || $requestedWith !== 'xmlhttprequest' ||
			!str_starts_with($contentType, 'application/json')) {
			throw new InvalidArgumentException('KeyHarbor administration requires an AJAX JSON POST.');
		}
	}
}
