<?php declare(strict_types=1);

namespace KeyHarbor\Service;

use Base3\Api\IClassMap;
use CredentialFoundation\Api\ICredentialServiceProvider;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use KeyHarbor\Exception\DuplicateCredentialServiceException;
use UnexpectedValueException;

/**
 * Collects all credential-protected services from classmap-discovered providers.
 */
final class CredentialServiceCatalog {

	/** @var array<string,CredentialServiceDefinition>|null */
	private ?array $services = null;

	public function __construct(
		private readonly IClassMap $classMap
	) {}

	/**
	 * @return array<int,CredentialServiceDefinition>
	 */
	public function getServices(): array {
		return array_values($this->getServiceMap());
	}

	public function getService(string $serviceId): ?CredentialServiceDefinition {
		$services = $this->getServiceMap();
		return $services[$serviceId] ?? null;
	}

	public function hasService(string $serviceId): bool {
		return $this->getService($serviceId) !== null;
	}

	/**
	 * @return array<string,CredentialServiceDefinition>
	 */
	private function getServiceMap(): array {
		if ($this->services !== null) {
			return $this->services;
		}

		$services = [];
		$providerNames = [];
		$providers = $this->classMap->getInstancesByInterface(ICredentialServiceProvider::class);

		foreach ($providers as $provider) {
			if (!$provider instanceof ICredentialServiceProvider) {
				throw new UnexpectedValueException(
					'Class map returned a credential service provider with an invalid type.'
				);
			}

			$providerName = $provider::getName();
			foreach ($provider->getServices() as $service) {
				if (!$service instanceof CredentialServiceDefinition) {
					throw new UnexpectedValueException(
						'Credential service provider "' . $providerName .
						'" returned a value that is not a CredentialServiceDefinition.'
					);
				}

				$serviceId = $service->getServiceId();
				if (isset($services[$serviceId])) {
					throw new DuplicateCredentialServiceException(
						$serviceId,
						$providerNames[$serviceId],
						$providerName
					);
				}

				$services[$serviceId] = $service;
				$providerNames[$serviceId] = $providerName;
			}
		}

		ksort($services, SORT_STRING);
		$this->services = $services;
		return $this->services;
	}
}
