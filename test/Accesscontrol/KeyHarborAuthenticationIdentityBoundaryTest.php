<?php declare(strict_types=1);

namespace KeyHarbor\Test\Accesscontrol;

use CredentialFoundation\Dto\CredentialIdentityResult;
use KeyHarbor\Accesscontrol\KeyHarborAuthentication;
use PHPUnit\Framework\TestCase;

final class KeyHarborAuthenticationIdentityBoundaryTest extends TestCase {

	public function testAuthenticatedCredentialWithoutHumanUserIdCannotCreateSession(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', null));
		$authentication = new KeyHarborAuthentication(
			new AuthenticationTestRequest(['HTTP_AUTHORIZATION' => 'Bearer valid-token']),
			$access,
			new AuthenticationTestUsermanager()
		);

		self::assertSame(0, $authentication->login());
		self::assertSame(1, $access->identifications);
		self::assertSame(0, $access->serviceAuthorizations);
	}

	public function testMalformedPrimaryHeaderDoesNotFallBackToRedirectBearer(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', 42));
		$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => 'Basic invalid',
			'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer valid-token'
		]), $access, new AuthenticationTestUsermanager());

		self::assertSame(0, $authentication->login());
		self::assertNull($access->bearerToken);
		self::assertSame(0, $access->identifications);
		self::assertSame(1, $access->resets);
	}

	public function testHmacTimestampNonceAndSignatureAreNormalizedBeforeIdentification(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', '42'));
		$authentication = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => 'Bearer valid-token',
			'HTTP_X_BASE3_TIMESTAMP' => ' 001785163584 ',
			'HTTP_X_BASE3_NONCE' => '  abc-nonce  ',
			'HTTP_X_BASE3_SIGNATURE' => ' ' . str_repeat('a', 64) . ' ',
			'REQUEST_METHOD' => 'GET',
			'REQUEST_URI' => '/api/resource?item=1'
		]), $access, new AuthenticationTestUsermanager());

		self::assertSame('user_42', $authentication->login());
		self::assertNotNull($access->hmacRequest);
		self::assertSame(1785163584, $access->hmacRequest->getTimestamp());
		self::assertSame('abc-nonce', $access->hmacRequest->getNonce());
		self::assertSame(str_repeat('a', 64), $access->hmacRequest->getSignature());
		self::assertNull($access->bearerToken);
	}

	public function testRepeatedLoginAlwaysResetsPreviouslyResolvedCredentials(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', 42));
		$authentication = new KeyHarborAuthentication(
			new AuthenticationTestRequest(['HTTP_AUTHORIZATION' => 'Bearer valid-token']),
			$access,
			new AuthenticationTestUsermanager()
		);

		self::assertSame('user_42', $authentication->login());
		self::assertSame('user_42', $authentication->login());
		self::assertSame(2, $access->resets);
		self::assertSame(2, $access->identifications);
		self::assertSame(0, $access->serviceAuthorizations);
	}

	public function testNativeIdIsResolvedToCurrentLoginIncludingNumericLogin(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', '42'));
		$authentication = new KeyHarborAuthentication(
			new AuthenticationTestRequest(['HTTP_AUTHORIZATION' => 'Bearer valid-token']),
			$access,
			new AuthenticationTestUsermanager(['42' => '007'])
		);
		self::assertSame('007', $authentication->login());
		self::assertSame('42', $access->getIdentity()?->getUserId());
	}

	public function testDeletedNativeIdIsDeniedWithoutLoginFallback(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', '42'));
		$authentication = new KeyHarborAuthentication(
			new AuthenticationTestRequest(['HTTP_AUTHORIZATION' => 'Bearer valid-token']),
			$access,
			new AuthenticationTestUsermanager([])
		);
		self::assertSame(0, $authentication->login());
	}
}

require_once __DIR__ . '/AuthenticationTestRequest.php';
require_once __DIR__ . '/AuthenticationTestCredentialAccess.php';
require_once __DIR__ . '/AuthenticationTestUsermanager.php';
