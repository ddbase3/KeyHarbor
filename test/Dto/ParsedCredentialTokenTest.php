<?php declare(strict_types=1);

namespace KeyHarbor\Test\Dto;

use KeyHarbor\Dto\ParsedCredentialToken;
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
