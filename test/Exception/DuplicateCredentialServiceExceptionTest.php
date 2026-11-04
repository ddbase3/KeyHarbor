<?php declare(strict_types=1);

namespace KeyHarbor\Test\Exception;

use KeyHarbor\Exception\DuplicateCredentialServiceException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DuplicateCredentialServiceExceptionTest extends TestCase {

	public function testCarriesBothConflictingProvidersAndServiceId(): void {
		$exception = new DuplicateCredentialServiceException('example:read', 'firstprovider', 'secondprovider');
		self::assertInstanceOf(RuntimeException::class, $exception);
		self::assertSame('example:read', $exception->getServiceId());
		self::assertSame('firstprovider', $exception->getFirstProviderName());
		self::assertSame('secondprovider', $exception->getSecondProviderName());
		self::assertSame('Credential service id "example:read" is provided by both "firstprovider" and "secondprovider".', $exception->getMessage());
	}
}
