<?php declare(strict_types=1);

namespace KeyHarbor\Test\Message;

use KeyHarbor\Message\KeyExpiredMessageTypeProvider;
use KeyHarbor\Message\KeyExpiringMessageTypeProvider;
use PHPUnit\Framework\TestCase;

final class KeyExpirationTemplatesConsistencyTest extends TestCase {

	public function testBothMessageTypesExposeExactlyTheSameRequiredContextFields(): void {
		foreach ([new KeyExpiredMessageTypeProvider(), new KeyExpiringMessageTypeProvider()] as $provider) {
			$schema = $provider->getSchema();
			$placeholders = $provider->getPlaceholders();
			$names = array_column($placeholders, 'name');

			self::assertSame($names, $schema['required']);
			self::assertSame($names, array_keys($schema['properties']));
			self::assertSame('object', $schema['type']);
			foreach ($placeholders as $placeholder) {
				self::assertTrue($placeholder['required']);
				self::assertSame(['type' => 'string'], $schema['properties'][$placeholder['name']]);
			}
		}
	}

	public function testDefaultTemplatesUseEachDeclaredRequiredPlaceholder(): void {
		foreach ([new KeyExpiredMessageTypeProvider(), new KeyExpiringMessageTypeProvider()] as $provider) {
			$templates = $provider->getDefaultSubject() . "\n" . $provider->getDefaultBodyText();
			foreach ($provider->getSchema()['required'] as $name) {
				self::assertStringContainsString('{{' . $name . '}}', $templates);
			}
			self::assertSame('', $provider->getDefaultBodyHtml());
		}
	}

	public function testExpiredAndExpiringTemplatesRemainDistinct(): void {
		$expired = new KeyExpiredMessageTypeProvider();
		$expiring = new KeyExpiringMessageTypeProvider();

		self::assertNotSame($expired::getName(), $expiring::getName());
		self::assertNotSame($expired->getDefaultSubject(), $expiring->getDefaultSubject());
		self::assertStringContainsString('expired on', $expired->getDefaultBodyText());
		self::assertStringContainsString('expires on', $expiring->getDefaultBodyText());
	}

	public function testTemplatesDoNotExposeBearerTokensOrSecretFields(): void {
		foreach ([new KeyExpiredMessageTypeProvider(), new KeyExpiringMessageTypeProvider()] as $provider) {
			$combined = $provider->getDefaultSubject() . $provider->getDefaultBodyText();
			self::assertStringNotContainsString('{{secret}}', $combined);
			self::assertStringNotContainsString('{{token}}', $combined);
			self::assertStringNotContainsString('{{secret_hash}}', $combined);
		}
	}
}
