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

use InvalidArgumentException;
use Base3\KeyHarbor\Exception\EncryptionProfileValidationException;
use PHPUnit\Framework\TestCase;

final class EncryptionProfileValidationExceptionTest extends TestCase {

	public function testExposesStableReasonWithoutChangingDiagnosticMessage(): void {
		$exception = new EncryptionProfileValidationException('invalid_key_id', 'The key identifier is not valid.');
		self::assertInstanceOf(InvalidArgumentException::class, $exception);
		self::assertSame('invalid_key_id', $exception->getReason());
		self::assertSame('The key identifier is not valid.', $exception->getMessage());
	}
}
