<?php declare(strict_types=1);

namespace KeyHarbor\Test\Accesscontrol;

use Base3\Api\IRequest;
use CredentialFoundation\Api\ICredentialAccess;
use CredentialFoundation\Dto\CredentialAuthenticationResult;
use CredentialFoundation\Dto\CredentialIdentityResult;
use CredentialFoundation\Dto\HmacAuthenticationRequest;
use KeyHarbor\Accesscontrol\KeyHarborAuthentication;
use PHPUnit\Framework\TestCase;

final class KeyHarborAuthenticationTest extends TestCase {

	public function testIgnoresRequestsWithoutCredentialHeaders(): void {
		$access = new AuthenticationTestCredentialAccess();
		$authentication = new KeyHarborAuthentication(
			new AuthenticationTestRequest(),
			$access
		);

		self::assertNull($authentication->login());
		self::assertSame(1, $access->resets);
		self::assertSame(0, $access->identifications);
	}

	public function testReturnsBearerCredentialOwnerWithoutServiceDecision(): void {
		$access = new AuthenticationTestCredentialAccess(
			CredentialIdentityResult::success('credential-1', '28038')
		);
		$authentication = new KeyHarborAuthentication(
			new AuthenticationTestRequest([
				'HTTP_AUTHORIZATION' => 'Bearer b3k_public_secret'
			]),
			$access
		);

		self::assertSame('28038', $authentication->login());
		self::assertSame('b3k_public_secret', $access->bearerToken);
		self::assertSame(0, $access->serviceAuthorizations);
	}

	public function testExplicitCredentialFailureOverridesOtherAuthentications(): void {
		$access = new AuthenticationTestCredentialAccess(
			CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_INVALID_CREDENTIAL
			)
		);
		$authentication = new KeyHarborAuthentication(
			new AuthenticationTestRequest([
				'HTTP_AUTHORIZATION' => 'Bearer invalid'
			]),
			$access
		);

		self::assertSame(0, $authentication->login());
	}

	public function testBuildsHmacRequestFromHttpRequest(): void {
		$access = new AuthenticationTestCredentialAccess(
			CredentialIdentityResult::success('credential-1', 42)
		);
		$authentication = new KeyHarborAuthentication(
			new AuthenticationTestRequest([
				'HTTP_AUTHORIZATION' => 'Bearer b3k_public_secret',
				'HTTP_X_BASE3_TIMESTAMP' => '1785163584',
				'HTTP_X_BASE3_NONCE' => 'nonce-1',
				'HTTP_X_BASE3_SIGNATURE' => str_repeat('a', 64),
				'REQUEST_METHOD' => 'POST',
				'REQUEST_URI' => '/api/example?mode=write',
				'QUERY_STRING' => 'mode=write'
			]),
			$access
		);

		self::assertSame(42, $authentication->login());
		self::assertInstanceOf(HmacAuthenticationRequest::class, $access->hmacRequest);
		self::assertSame('POST', $access->hmacRequest?->getMethod());
		self::assertSame('/api/example', $access->hmacRequest?->getPath());
		self::assertSame('mode=write', $access->hmacRequest?->getQueryString());
		self::assertSame(1785163584, $access->hmacRequest?->getTimestamp());
		self::assertSame('nonce-1', $access->hmacRequest?->getNonce());
		self::assertSame(0, $access->serviceAuthorizations);
	}
}

final class AuthenticationTestCredentialAccess implements ICredentialAccess {

	public int $resets = 0;
	public int $identifications = 0;
	public int $serviceAuthorizations = 0;
	public ?string $bearerToken = null;
	public ?HmacAuthenticationRequest $hmacRequest = null;

	public function __construct(
		private readonly ?CredentialIdentityResult $result = null
	) {}

	public function reset(): void {
		$this->resets++;
	}

	public function identifyBearer(string $token): CredentialIdentityResult {
		$this->identifications++;
		$this->bearerToken = $token;

		return $this->result ?? CredentialIdentityResult::failure(
			CredentialIdentityResult::FAILURE_INVALID_CREDENTIAL
		);
	}

	public function identifyHmac(HmacAuthenticationRequest $request): CredentialIdentityResult {
		$this->identifications++;
		$this->hmacRequest = $request;

		return $this->result ?? CredentialIdentityResult::failure(
			CredentialIdentityResult::FAILURE_INVALID_CREDENTIAL
		);
	}

	public function authorizeService(string $serviceId): CredentialAuthenticationResult {
		$this->serviceAuthorizations++;
		return CredentialAuthenticationResult::failure(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_GRANTED,
			$serviceId
		);
	}

	public function getIdentity(): ?CredentialIdentityResult {
		return $this->result;
	}
}

final class AuthenticationTestRequest implements IRequest {

	/** @param array<string,mixed> $server */
	public function __construct(private readonly array $server = []) {}

	public function get(string $key, $default = null) { return $default; }
	public function post(string $key, $default = null) { return $default; }
	public function request(string $key, $default = null) { return $default; }
	public function allRequest(): array { return []; }
	public function cookie(string $key, $default = null) { return $default; }
	public function session(string $key, $default = null) { return $default; }
	public function server(string $key, $default = null) { return $this->server[$key] ?? $default; }
	public function files(string $key, $default = null) { return $default; }
	public function allGet(): array { return []; }
	public function allPost(): array { return []; }
	public function allCookie(): array { return []; }
	public function allSession(): array { return []; }
	public function allServer(): array { return $this->server; }
	public function allFiles(): array { return []; }
	public function getJsonBody(): array { return []; }
	public function isCli(): bool { return false; }
	public function getContext(): string { return IRequest::CONTEXT_WEB_API; }
}
