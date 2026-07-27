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

final class FirstCatalogProvider implements ICredentialServiceProvider {

	public static function getName(): string {
		return 'firstcatalogprovider';
	}

	public function getServices(): array {
		return [
			new CredentialServiceDefinition('example:write', 'Example write'),
			new CredentialServiceDefinition('example:read', 'Example read')
		];
	}
}

final class SecondCatalogProvider implements ICredentialServiceProvider {

	public static function getName(): string {
		return 'secondcatalogprovider';
	}

	public function getServices(): array {
		return [
			new CredentialServiceDefinition('other:ping', 'Other ping')
		];
	}
}

final class DuplicateCatalogProvider implements ICredentialServiceProvider {

	public static function getName(): string {
		return 'duplicatecatalogprovider';
	}

	public function getServices(): array {
		return [
			new CredentialServiceDefinition('example:read', 'Duplicate example read')
		];
	}
}

final class InvalidCatalogProvider implements ICredentialServiceProvider {

	public static function getName(): string {
		return 'invalidcatalogprovider';
	}

	public function getServices(): array {
		return ['invalid'];
	}
}

final class CatalogTestClassMap implements IClassMap {

	/** @var array<int,object> */
	private array $instances;
	/** @var array<int,object> */
	private array $empty = [];

	/** @param array<int,object> $instances */
	public function __construct(array $instances) {
		$this->instances = $instances;
	}

	public function instantiate(string $class) {
		return null;
	}

	public function instantiateWith(string $class, array $arguments = []) {
		return null;
	}

	public function generate($regenerate = false): void {}

	public function getApps() {
		return [];
	}

	public function &getInstances(array $criteria = []) {
		return $this->empty;
	}

	public function &getInstancesByInterface($interface) {
		return $this->instances;
	}

	public function &getInstancesByAppInterface($app, $interface, $retry = false) {
		return $this->empty;
	}

	public function &getInstanceByAppName($app, $name, $retry = false) {
		$result = null;
		return $result;
	}

	public function getClassByInterfaceName(string $interface, string $name): ?string {
		return null;
	}

	public function &getInstanceByInterfaceName($interface, $name, $retry = false) {
		$result = null;
		return $result;
	}

	public function &getInstanceByAppInterfaceName($app, $interface, $name, $retry = false) {
		$result = null;
		return $result;
	}

	public function getPlugins() {
		return [];
	}
}
