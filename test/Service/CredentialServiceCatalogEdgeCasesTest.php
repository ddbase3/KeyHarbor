<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use CredentialFoundation\Api\ICredentialServiceProvider;
use KeyHarbor\Exception\DuplicateCredentialServiceException;
use KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

final class CredentialServiceCatalogEdgeCasesTest extends TestCase {

	public function testEmptyCatalogHasNoServices(): void {
		$catalog = new CredentialServiceCatalog(new CatalogTestClassMap([]));

		self::assertSame([], $catalog->getServices());
		self::assertNull($catalog->getService('example:read'));
		self::assertFalse($catalog->hasService('example:read'));
	}

	public function testCatalogRejectsNonProviderInstances(): void {
		$catalog = new CredentialServiceCatalog(new CatalogTestClassMap([new stdClass()]));

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('invalid type');
		$catalog->getServices();
	}

	public function testCatalogCachesSuccessfulDiscovery(): void {
		$classMap = new CatalogTestClassMap([new FirstCatalogProvider()]);
		$catalog = new CredentialServiceCatalog($classMap);
		self::assertCount(2, $catalog->getServices());

		$instances = &$classMap->getInstancesByInterface(ICredentialServiceProvider::class);
		$instances[] = new SecondCatalogProvider();

		self::assertCount(2, $catalog->getServices());
		self::assertNull($catalog->getService('other:ping'));
		self::assertFalse($catalog->hasService('other:ping'));
	}

	public function testSeparateCatalogsDoNotShareDiscoveredServiceMap(): void {
		$first = new CredentialServiceCatalog(new CatalogTestClassMap([new FirstCatalogProvider()]));
		$second = new CredentialServiceCatalog(new CatalogTestClassMap([new SecondCatalogProvider()]));

		self::assertTrue($first->hasService('example:read'));
		self::assertFalse($first->hasService('other:ping'));
		self::assertTrue($second->hasService('other:ping'));
		self::assertFalse($second->hasService('example:read'));
	}

	public function testFailedDiscoveryDoesNotCacheAnIncompleteServiceMap(): void {
		$classMap = new CatalogTestClassMap([new FirstCatalogProvider(), new DuplicateCatalogProvider()]);
		$catalog = new CredentialServiceCatalog($classMap);
		try {
			$catalog->getServices();
			self::fail('Expected duplicate service to be rejected.');
		} catch (DuplicateCredentialServiceException $exception) {
			self::assertSame('example:read', $exception->getServiceId());
		}

		$instances = &$classMap->getInstancesByInterface(ICredentialServiceProvider::class);
		$instances = [new SecondCatalogProvider()];

		self::assertCount(1, $catalog->getServices());
		self::assertTrue($catalog->hasService('other:ping'));
		self::assertFalse($catalog->hasService('example:read'));
	}
}

require_once __DIR__ . '/CatalogTestClassMap.php';
require_once __DIR__ . '/FirstCatalogProvider.php';
require_once __DIR__ . '/SecondCatalogProvider.php';
require_once __DIR__ . '/DuplicateCatalogProvider.php';
