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

namespace Base3\KeyHarbor\Test\Dto;

use Base3\KeyHarbor\Dto\ParsedCredentialToken;
use PHPUnit\Framework\TestCase;

final class ParsedCredentialTokenTest extends TestCase {

	public function testKeepsParsedPublicIdSeparateFromSecret(): void {
		$publicId = str_repeat('a', 20);
		$secret = str_repeat('B', 43);
		$parsed = new ParsedCredentialToken($publicId, $secret);
		self::assertSame($publicId, $parsed->getPublicId());
		self::assertSame($secret, $parsed->getSecret());
	}

	public function testDoesNotReencodeOrChangeCaseOfParsedComponents(): void {
		$parsed = new ParsedCredentialToken('ab01', 'Ab0_-');
		self::assertSame('ab01', $parsed->getPublicId());
		self::assertSame('Ab0_-', $parsed->getSecret());
	}
}
