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

final class KeyExpirationMessageTypeProviderTest extends TestCase {

	public function testExpiringProviderExposesStableTypeAndPlaceholders(): void {
		$provider = new KeyExpiringMessageTypeProvider();

		self::assertSame('keyharborexpiring', $provider::getName());
		self::assertStringContainsString('{{key_label}}', $provider->getDefaultSubject());
		self::assertSame('', $provider->getDefaultBodyHtml());
		self::assertSame(
			['user_name', 'key_label', 'key_public_id', 'expires_at', 'service_labels', 'system_name', 'manage_url'],
			array_column($provider->getPlaceholders(), 'name')
		);
	}

	public function testExpiredProviderExposesStableTypeAndSchema(): void {
		$provider = new KeyExpiredMessageTypeProvider();
		$schema = $provider->getSchema();

		self::assertSame('keyharborexpired', $provider::getName());
		self::assertContains('key_public_id', $schema['required']);
		self::assertArrayHasKey('manage_url', $schema['properties']);
	}
}
