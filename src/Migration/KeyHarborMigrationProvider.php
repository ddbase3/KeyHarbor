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

namespace Base3\KeyHarbor\Migration;

use Base3\CoreFoundation\Migration\Api\IDatabaseMigrationProvider;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\Repository\DatabaseCredentialRepository;

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
			Migration001CreateCredentialTables::class,
			Migration002CredentialSubjects::class,
			Migration003ResourceConstraints::class
		];
	}
}
