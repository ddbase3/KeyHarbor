<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Api\IClassMap;
use CredentialFoundation\Api\ICredentialServiceProvider;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use KeyHarbor\Exception\DuplicateCredentialServiceException;
use KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class InvalidCatalogProvider implements ICredentialServiceProvider {

	public static function getName(): string {
		return 'invalidcatalogprovider';
	}

	public function getServices(): array {
		return ['invalid'];
	}
}
