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

namespace Base3\KeyHarbor\Accesscontrol;

use Base3\CoreFoundation\Accesscontrol\AbstractAuth;
use Base3\CoreFoundation\Api\IRequest;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CredentialFoundation\Api\ICredentialAccess;
use Base3\CredentialFoundation\Dto\CredentialIdentityResult;
use Base3\CredentialFoundation\Dto\HmacAuthenticationRequest;
use InvalidArgumentException;

/**
 * Resolves a verified native KeyHarbor user id into the host authentication login.
 * Native User::$id and the host account login User::$userid are never interchangeable.
 */
final class KeyHarborAuthentication extends AbstractAuth {

	public function __construct(
		private readonly IRequest $request,
		private readonly ICredentialAccess $credentialAccess,
		private readonly IUsermanager $usermanager
	) {}

	public static function getName(): string {
		return 'keyharborauthentication';
	}

	public function login(?array $credentials = null) {
		if ($credentials !== null) return null;
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

		// A service credential must never be a human session.
		if ($result->getSubject()?->isService() === true) {
			return 0;
		}
		// KeyHarbor's user id is the native User::$id, not the login key
		// expected by Base3SystemUsermanager for the current request.
		$userId = $result->getUserId();
		if ($userId === null || trim((string)$userId) === '') {
			return 0;
		}
		$user = $this->usermanager->getUserById($userId);
		if ($user === null || (string)$user->id !== (string)$userId ||
			!is_string($user->userid) || trim($user->userid) === '') {
			return 0;
		}

		return $user->userid;
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
				$this->getRequestQueryString(),
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

	/**
	 * Read the externally requested query, not the server's internally rewritten QUERY_STRING.
	 */
	private function getRequestQueryString(): string {
		$requestUri = (string)$this->request->server('REQUEST_URI', '/');
		$query = parse_url($requestUri, PHP_URL_QUERY);
		return is_string($query) ? $query : '';
	}

	private function getRawBody(): string {
		$body = file_get_contents('php://input');
		return is_string($body) ? $body : '';
	}
}
