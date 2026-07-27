<?php declare(strict_types=1);

namespace KeyHarbor\Test\Message;

use KeyHarbor\Message\KeyExpiredMessageTypeProvider;
use KeyHarbor\Message\KeyExpiringMessageTypeProvider;
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
