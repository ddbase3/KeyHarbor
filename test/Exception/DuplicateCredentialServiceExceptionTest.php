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

use Base3\KeyHarbor\Exception\DuplicateCredentialServiceException;
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
