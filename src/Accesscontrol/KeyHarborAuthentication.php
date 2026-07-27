<?php declare(strict_types=1);

namespace KeyHarbor\Accesscontrol;

use Base3\Accesscontrol\AbstractAuth;
use Base3\Api\IRequest;
use CredentialFoundation\Api\ICredentialAccess;
use CredentialFoundation\Dto\CredentialIdentityResult;
use CredentialFoundation\Dto\HmacAuthenticationRequest;
use InvalidArgumentException;

/**
 * Resolves a KeyHarbor bearer or HMAC credential into the current BASE3 user id.
 */
final class KeyHarborAuthentication extends AbstractAuth {

	public function __construct(
		private readonly IRequest $request,
		private readonly ICredentialAccess $credentialAccess
	) {}

	public static function getName(): string {
		return 'keyharborauthentication';
	}

	public function login() {
		$this->credentialAccess->reset();

		$authorization = $this->getAuthorizationHeader();
		$hasHmacHeaders = $this->hasHmacHeaders();
		if ($authorization === '' && !$hasHmacHeaders) {
			return null;
		}

		$token = $this->parseBearerToken($authorization);
		if ($token === null) {
			return 0;
		}

		$result = $hasHmacHeaders
			? $this->identifyHmac($token)
			: $this->credentialAccess->identifyBearer($token);

		if (!$result->isAuthenticated()) {
			return 0;
		}

		$userId = $result->getUserId();
		if ($userId === null || trim((string)$userId) === '') {
			return 0;
		}

		return $userId;
	}

	private function identifyHmac(string $token): CredentialIdentityResult {
		$timestamp = $this->readPositiveIntegerHeader('HTTP_X_BASE3_TIMESTAMP');
		$nonce = trim((string)$this->request->server('HTTP_X_BASE3_NONCE', ''));
		$signature = trim((string)$this->request->server('HTTP_X_BASE3_SIGNATURE', ''));

		if ($timestamp === null || $nonce === '' || $signature === '') {
			return CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_INVALID_SIGNATURE
			);
		}

		try {
			$request = new HmacAuthenticationRequest(
				$token,
				(string)$this->request->server('REQUEST_METHOD', 'GET'),
				$this->getRequestPath(),
				(string)$this->request->server('QUERY_STRING', ''),
				$timestamp,
				$nonce,
				$signature,
				$this->getRawBody()
			);
		} catch (InvalidArgumentException) {
			return CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_INVALID_SIGNATURE
			);
		}

		return $this->credentialAccess->identifyHmac($request);
	}

	private function getAuthorizationHeader(): string {
		$authorization = trim((string)$this->request->server('HTTP_AUTHORIZATION', ''));
		if ($authorization === '') {
			$authorization = trim((string)$this->request->server('REDIRECT_HTTP_AUTHORIZATION', ''));
		}

		return $authorization;
	}

	private function parseBearerToken(string $authorization): ?string {
		if (!preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
			return null;
		}

		return $matches[1];
	}

	private function hasHmacHeaders(): bool {
		return trim((string)$this->request->server('HTTP_X_BASE3_TIMESTAMP', '')) !== '' ||
			trim((string)$this->request->server('HTTP_X_BASE3_NONCE', '')) !== '' ||
			trim((string)$this->request->server('HTTP_X_BASE3_SIGNATURE', '')) !== '';
	}

	private function readPositiveIntegerHeader(string $name): ?int {
		$value = trim((string)$this->request->server($name, ''));
		if ($value === '' || !ctype_digit($value)) {
			return null;
		}

		$timestamp = (int)$value;
		return $timestamp > 0 ? $timestamp : null;
	}

	private function getRequestPath(): string {
		$requestUri = (string)$this->request->server('REQUEST_URI', '/');
		$path = parse_url($requestUri, PHP_URL_PATH);
		if (!is_string($path) || $path === '' || $path[0] !== '/') {
			return '/';
		}

		return $path;
	}

	private function getRawBody(): string {
		$body = file_get_contents('php://input');
		return is_string($body) ? $body : '';
	}
}
