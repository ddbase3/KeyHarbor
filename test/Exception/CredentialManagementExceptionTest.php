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

namespace Base3\KeyHarbor\Test\Exception;

use Base3\KeyHarbor\Exception\CredentialManagementException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CredentialManagementExceptionTest extends TestCase {

	public function testPreservesStableReasonIndependentlyOfDiagnosticText(): void {
		$exception = new CredentialManagementException(CredentialManagementException::ACCESS_DENIED, 'Not authorized.');
		self::assertInstanceOf(RuntimeException::class, $exception);
		self::assertSame('access_denied', $exception->getReason());
		self::assertSame('Not authorized.', $exception->getMessage());
	}

	public function testAllDefinedManagementReasonsRemainDistinct(): void {
		$reasons = [
			CredentialManagementException::NOT_AUTHENTICATED,
			CredentialManagementException::ACCESS_DENIED,
			CredentialManagementException::NOT_FOUND,
			CredentialManagementException::REVOKED,
			CredentialManagementException::NOT_REVOKED,
			CredentialManagementException::EXPIRED,
			CredentialManagementException::UNSUPPORTED_MODE
		];
		self::assertCount(count($reasons), array_unique($reasons));
		foreach ($reasons as $reason) {
			self::assertMatchesRegularExpression('/^[a-z_]+$/', $reason);
			self::assertSame($reason, (new CredentialManagementException($reason, 'error'))->getReason());
		}
	}
}
