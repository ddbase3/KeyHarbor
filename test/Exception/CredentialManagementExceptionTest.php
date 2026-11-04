<?php declare(strict_types=1);

namespace KeyHarbor\Test\Exception;

use KeyHarbor\Exception\CredentialManagementException;
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
