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

use Base3\KeyHarbor\Exception\CredentialHmacConfigurationException;
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
