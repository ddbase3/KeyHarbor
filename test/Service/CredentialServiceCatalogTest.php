<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Api\IClassMap;
use CredentialFoundation\Api\ICredentialServiceProvider;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use KeyHarbor\Exception\DuplicateCredentialServiceException;
use KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class CredentialServiceCatalogTest extends TestCase {

	public function testCollectsAndSortsServicesFromAllProviders(): void {
		$catalog = new CredentialServiceCatalog(new CatalogTestClassMap([
			new SecondCatalogProvider(),
			new FirstCatalogProvider()
		]));

		$services = $catalog->getServices();

		self::assertSame(
			['example:read', 'example:write', 'other:ping'],
			array_map(
				fn(CredentialServiceDefinition $service): string => $service->getServiceId(),
				$services
			)
		);
		self::assertSame('Example read', $catalog->getService('example:read')?->getLabel());
		self::assertTrue($catalog->hasService('other:ping'));
		self::assertFalse($catalog->hasService('missing:service'));
	}

	public function testRejectsDuplicateServiceIdsWithoutChoosingAWinner(): void {
		$catalog = new CredentialServiceCatalog(new CatalogTestClassMap([
			new FirstCatalogProvider(),
			new DuplicateCatalogProvider()
		]));

		try {
			$catalog->getServices();
			self::fail('Expected duplicate service exception.');
		} catch (DuplicateCredentialServiceException $exception) {
			self::assertSame('example:read', $exception->getServiceId());
			self::assertSame(FirstCatalogProvider::getName(), $exception->getFirstProviderName());
			self::assertSame(DuplicateCatalogProvider::getName(), $exception->getSecondProviderName());
		}
	}

	public function testRejectsInvalidProviderValues(): void {
		$catalog = new CredentialServiceCatalog(new CatalogTestClassMap([
			new InvalidCatalogProvider()
		]));

		$this->expectException(UnexpectedValueException::class);
		$catalog->getServices();
	}
}

require_once __DIR__ . '/FirstCatalogProvider.php';

require_once __DIR__ . '/SecondCatalogProvider.php';

require_once __DIR__ . '/DuplicateCatalogProvider.php';

require_once __DIR__ . '/InvalidCatalogProvider.php';

require_once __DIR__ . '/CatalogTestClassMap.php';
