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

namespace Base3\KeyHarbor\Test\Accesscontrol;

use Base3\CredentialFoundation\Dto\CredentialIdentityResult;
use Base3\KeyHarbor\Accesscontrol\KeyHarborAuthentication;
use PHPUnit\Framework\TestCase;

final class KeyHarborAuthenticationParsingTest extends TestCase {

	public function testAuthenticationHasStableDiscoverableName(): void {
		self::assertSame('keyharborauthentication', KeyHarborAuthentication::getName());
	}

	public function testWhitespacePrimaryAuthorizationFallsBackToRedirectHeader(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', 42));
		$auth = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => '  ',
			'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirected-token'
		]), $access, new AuthenticationTestUsermanager());
		self::assertSame('user_42', $auth->login());
		self::assertSame('redirected-token', $access->bearerToken);
		self::assertSame(1, $access->identifications);
	}

	public function testLineBreaksInsideBearerTokenCannotBeAuthenticated(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', 42));
		$auth = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => "Bearer abc\ndef"
		]), $access, new AuthenticationTestUsermanager());
		self::assertSame(0, $auth->login());
		self::assertSame(0, $access->identifications);
		self::assertSame(1, $access->resets);
	}

	public function testPartialHmacHeadersCannotDowngradeToBearerAuthentication(): void {
		foreach (['HTTP_X_BASE3_TIMESTAMP', 'HTTP_X_BASE3_NONCE', 'HTTP_X_BASE3_SIGNATURE'] as $header) {
			$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', 42));
			$auth = new KeyHarborAuthentication(new AuthenticationTestRequest([
				'HTTP_AUTHORIZATION' => 'Bearer valid-token',
				$header => $header === 'HTTP_X_BASE3_TIMESTAMP' ? '1785163584' : 'nonce-or-signature'
			]), $access, new AuthenticationTestUsermanager());
			self::assertSame(0, $auth->login(), $header);
			self::assertNull($access->bearerToken, $header);
			self::assertSame(0, $access->identifications, $header);
		}
	}

	public function testRawExternalRequestQueryExcludesFragmentAndInternalRewrite(): void {
		$access = new AuthenticationTestCredentialAccess(CredentialIdentityResult::success('credential', 42));
		$auth = new KeyHarborAuthentication(new AuthenticationTestRequest([
			'HTTP_AUTHORIZATION' => 'Bearer valid-token',
			'HTTP_X_BASE3_TIMESTAMP' => '1785163584',
			'HTTP_X_BASE3_NONCE' => 'nonce',
			'HTTP_X_BASE3_SIGNATURE' => str_repeat('a', 64),
			'REQUEST_URI' => '/public/api?name=value%2B1&name=two#fragment',
			'QUERY_STRING' => 'name=rewritten&out=php'
		]), $access, new AuthenticationTestUsermanager());
		self::assertSame('user_42', $auth->login());
		self::assertSame('/public/api', $access->hmacRequest?->getPath());
		self::assertSame('name=value%2B1&name=two', $access->hmacRequest?->getQueryString());
	}
}

require_once __DIR__ . '/AuthenticationTestRequest.php';
require_once __DIR__ . '/AuthenticationTestCredentialAccess.php';
require_once __DIR__ . '/AuthenticationTestUsermanager.php';
