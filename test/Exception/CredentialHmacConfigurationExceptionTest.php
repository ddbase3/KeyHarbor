<?php declare(strict_types=1);

namespace KeyHarbor\Test\Exception;

use KeyHarbor\Exception\CredentialHmacConfigurationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CredentialHmacConfigurationExceptionTest extends TestCase {

	public function testRetainsPreviousCauseAndTechnicalConfigurationDetails(): void {
		$previous = new RuntimeException('Missing master key.');
		$exception = new CredentialHmacConfigurationException('Invalid HMAC configuration.', 3, $previous);
		self::assertInstanceOf(RuntimeException::class, $exception);
		self::assertSame('Invalid HMAC configuration.', $exception->getMessage());
		self::assertSame(3, $exception->getCode());
		self::assertSame($previous, $exception->getPrevious());
	}
}
