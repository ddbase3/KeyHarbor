<?php declare(strict_types=1);

namespace KeyHarbor\Test\Exception;

use InvalidArgumentException;
use KeyHarbor\Exception\EncryptionProfileValidationException;
use PHPUnit\Framework\TestCase;

final class EncryptionProfileValidationExceptionTest extends TestCase {

	public function testExposesStableReasonWithoutChangingDiagnosticMessage(): void {
		$exception = new EncryptionProfileValidationException('invalid_key_id', 'The key identifier is not valid.');
		self::assertInstanceOf(InvalidArgumentException::class, $exception);
		self::assertSame('invalid_key_id', $exception->getReason());
		self::assertSame('The key identifier is not valid.', $exception->getMessage());
	}
}
