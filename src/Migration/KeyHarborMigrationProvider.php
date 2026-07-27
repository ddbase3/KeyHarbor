<?php declare(strict_types=1);

namespace KeyHarbor\Migration;

use Base3\Migration\Api\IDatabaseMigrationProvider;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Repository\DatabaseCredentialRepository;

/**
 * Provides KeyHarbor schema migrations when the database repository is active.
 */
final class KeyHarborMigrationProvider implements IDatabaseMigrationProvider {

	public function __construct(
		private readonly ICredentialRepository $repository
	) {}

	public static function getName(): string {
		return 'keyharbormigrationprovider';
	}

	public function isActive(): bool {
		return $this->repository instanceof DatabaseCredentialRepository;
	}

	public function getMigrations(): array {
		return [
			Migration001CreateCredentialTables::class
		];
	}
}
