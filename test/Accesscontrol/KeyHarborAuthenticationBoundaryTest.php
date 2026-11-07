<?php declare(strict_types=1);

namespace KeyHarbor\Test\Accesscontrol;

use CredentialFoundation\Dto\CredentialIdentityResult;
use KeyHarbor\Accesscontrol\KeyHarborAuthentication;
use PHPUnit\Framework\TestCase;

final class KeyHarborAuthenticationBoundaryTest extends TestCase {

	public function testRedirectAuthorizationHeaderIsAcceptedWhenPrimaryHeaderIsMissing(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential-1', 42));
		$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected-token'
		]), $access, new AuthenticationTestUsermanager());

		self::assertSame('user_42', $authentication->login());
		self::assertSame('redirected-token', $access->bearerToken);
		self::assertSame(1, $access->resets);
		self::assertSame(1, $access->identifications);
	}

	public function testPrimaryAuthorizationHeaderTakesPrecedenceOverRedirectHeader(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential-1', '42'));
		$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => 'Bearer primary-token',
			'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected-token'
		]), $access, new AuthenticationTestUsermanager());

		self::assertSame('user_42', $authentication->login());
		self::assertSame('primary-token', $access->bearerToken);
	}

	public function testBearerSchemeIsCaseInsensitiveAndHeaderWhitespaceIsIgnored(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential-1', 42));
		$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => "  bEaReR\tcredential-token  "
		]), $access, new AuthenticationTestUsermanager());

		self::assertSame('user_42', $authentication->login());
		self::assertSame('credential-token', $access->bearerToken);
	}

	public function testMalformedAuthorizationSchemesNeverReachCredentialAccess(): void {
		foreach (['Basic abc', 'Bearer', 'Bearer a b', 'Bearer token extra', 'token'] as $header) {
			$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential-1', 42));
			$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
				'HTTP_AUTHORIZATION' => $header
			]), $access, new AuthenticationTestUsermanager());

			self::assertSame(0, $authentication->login(), $header);
			self::assertSame(1, $access->resets, $header);
			self::assertSame(0, $access->identifications, $header);
		}
	}

	public function testHmacHeadersWithoutBearerTokenNeverIdentifyCredentials(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential-1', 42));
		$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_X_BASE3_TIMESTAMP' => '1785163584',
			'HTTP_X_BASE3_NONCE' => 'nonce',
			'HTTP_X_BASE3_SIGNATURE' => str_repeat('a', 64)
		]), $access, new AuthenticationTestUsermanager());

		self::assertSame(0, $authentication->login());
		self::assertSame(0, $access->identifications);
		self::assertSame(1, $access->resets);
	}

	public function testMissingHmacSignatureRejectsBeforeAccessLookup(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential-1', 42));
		$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => 'Bearer token',
			'HTTP_X_BASE3_TIMESTAMP' => '1785163584',
			'HTTP_X_BASE3_NONCE' => 'nonce'
		]), $access, new AuthenticationTestUsermanager());

		self::assertSame(0, $authentication->login());
		self::assertSame(0, $access->identifications);
	}

	public function testNonPositiveOrNonDecimalHmacTimestampsAreRejected(): void {
		foreach (['0', '-1', '1.5', '1e6', 'today', ''] as $timestamp) {
			$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential-1', 42));
			$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
				'HTTP_AUTHORIZATION' => 'Bearer token',
				'HTTP_X_BASE3_TIMESTAMP' => $timestamp,
				'HTTP_X_BASE3_NONCE' => 'nonce',
				'HTTP_X_BASE3_SIGNATURE' => str_repeat('a', 64)
			]), $access, new AuthenticationTestUsermanager());

			self::assertSame(0, $authentication->login(), $timestamp);
			self::assertSame(0, $access->identifications, $timestamp);
		}
	}

	public function testMissingOrWhitespaceHmacNonceIsRejectedBeforeAccessLookup(): void {
		foreach (['', "\t  "] as $nonce) {
			$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential-1', 42));
			$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
				'HTTP_AUTHORIZATION' => 'Bearer token',
				'HTTP_X_BASE3_TIMESTAMP' => '1785163584',
				'HTTP_X_BASE3_NONCE' => $nonce,
				'HTTP_X_BASE3_SIGNATURE' => str_repeat('a', 64)
			]), $access, new AuthenticationTestUsermanager());

			self::assertSame(0, $authentication->login());
			self::assertSame(0, $access->identifications);
		}
	}

	public function testFailedHmacIdentificationNeverFallsBackToBearer(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::failure(
			CredentialIdentityResult::FAILURE_INVALID_SIGNATURE
		));
		$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => 'Bearer token',
			'HTTP_X_BASE3_TIMESTAMP' => '1785163584',
			'HTTP_X_BASE3_NONCE' => 'nonce',
			'HTTP_X_BASE3_SIGNATURE' => str_repeat('a', 64)
		]), $access, new AuthenticationTestUsermanager());

		self::assertSame(0, $authentication->login());
		self::assertSame(1, $access->identifications);
		self::assertNull($access->bearerToken);
		self::assertNotNull($access->hmacRequest);
		self::assertSame(0, $access->serviceAuthorizations);
	}

	public function testInvalidRequestPathFallsBackToRootDuringHmacIdentification(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential-1', 42));
		$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => 'Bearer token',
			'HTTP_X_BASE3_TIMESTAMP' => '1785163584',
			'HTTP_X_BASE3_NONCE' => 'nonce',
			'HTTP_X_BASE3_SIGNATURE' => str_repeat('a', 64),
			'REQUEST_URI' => 'relative-request-path'
		]), $access, new AuthenticationTestUsermanager());

		self::assertSame('user_42', $authentication->login());
		self::assertSame('/', $access->hmacRequest?->getPath());
		self::assertSame('', $access->hmacRequest?->getQueryString());
	}
}

require_once __DIR__ . '/AuthenticationTestRequest.php';
require_once __DIR__ . '/AuthenticationTestCredentialAccess.php';
require_once __DIR__ . '/AuthenticationTestUsermanager.php';
