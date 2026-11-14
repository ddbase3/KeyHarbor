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

namespace Base3\KeyHarbor\Test\Message;

use Base3\KeyHarbor\Message\KeyExpiredMessageTypeProvider;
use Base3\KeyHarbor\Message\KeyExpiringMessageTypeProvider;
use PHPUnit\Framework\TestCase;

final class KeyExpirationProviderDetailsTest extends TestCase {

	public function testBothProvidersExposeNonemptyNamesLabelsAndDescriptions(): void {
		foreach ($this->providers() as $provider) {
			self::assertNotSame('', $provider::getName());
			self::assertNotSame('', $provider->getLabel());
			self::assertNotSame('', $provider->getDescription());
			self::assertNotSame('', $provider->getDefaultSubject());
			self::assertSame('', $provider->getDefaultBodyHtml());
		}
	}

	public function testBothBodiesContainOnlyDeclaredUserFacingPlaceholders(): void {
		foreach ($this->providers() as $provider) {
			$placeholders = array_column($provider->getPlaceholders(), 'name');
			$body = $provider->getDefaultSubject() . "\n" . $provider->getDefaultBodyText();
			preg_match_all('/\{\{([a-z_]+)\}\}/', $body, $matches);
			self::assertSame([], array_values(array_diff(array_unique($matches[1]), $placeholders)));
			self::assertContains('manage_url', $matches[1]);
			self::assertContains('service_labels', $matches[1]);
			self::assertContains('system_name', $matches[1]);
		}
	}

	public function testEachPlaceholderHasCompleteRequiredMetadata(): void {
		foreach ($this->providers() as $provider) {
			$placeholders = $provider->getPlaceholders();
			self::assertCount(7, $placeholders);
			foreach ($placeholders as $placeholder) {
				self::assertNotSame('', $placeholder['name']);
				self::assertNotSame('', $placeholder['label']);
				self::assertNotSame('', $placeholder['description']);
				self::assertNotSame('', $placeholder['example']);
				self::assertTrue($placeholder['required']);
			}
		}
	}

	public function testSchemaExactlyMatchesPlaceholderNamesAndStringTypes(): void {
		foreach ($this->providers() as $provider) {
			$schema = $provider->getSchema();
			$names = array_column($provider->getPlaceholders(), 'name');
			self::assertSame('object', $schema['type']);
			self::assertSame($names, $schema['required']);
			self::assertSame($names, array_keys($schema['properties']));
			foreach ($schema['properties'] as $property) {
				self::assertSame(['type' => 'string'], $property);
			}
		}
	}

	public function testWarningAndExpirationMessagesCannotBeConfused(): void {
		$warning = new KeyExpiringMessageTypeProvider();
		$expired = new KeyExpiredMessageTypeProvider();
		self::assertSame('keyharborexpiring', $warning::getName());
		self::assertSame('keyharborexpired', $expired::getName());
		self::assertStringContainsString('expires', $warning->getDefaultBodyText());
		self::assertStringContainsString('expired', $expired->getDefaultBodyText());
		self::assertNotSame($warning->getDefaultSubject(), $expired->getDefaultSubject());
		self::assertNotSame($warning->getDefaultBodyText(), $expired->getDefaultBodyText());
	}

	/** @return array<int,object> */
	private function providers(): array {
		return [new KeyExpiringMessageTypeProvider(), new KeyExpiredMessageTypeProvider()];
	}
}
