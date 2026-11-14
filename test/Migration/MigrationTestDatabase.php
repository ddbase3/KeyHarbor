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

namespace Base3\KeyHarbor\Test\Migration;

use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\KeyHarbor\Migration\Migration001CreateCredentialTables;
use Base3\KeyHarbor\Migration\Migration002CredentialSubjects;
use PHPUnit\Framework\TestCase;

final class MigrationTestDatabase implements IDatabase {

	/** @var array<int,string> */
	public array $queries = [];
	private bool $connected = false;

	public function connect(): void {
		$this->connected = true;
	}

	public function connected(): bool {
		return $this->connected;
	}

	public function disconnect(): void {
		$this->connected = false;
	}

	public function beginTransaction(): void {}

	public function commit(): void {}

	public function rollback(): void {}

	public function nonQuery(string $query): void {
		$this->queries[] = $query;
	}

	public function scalarQuery(string $query): mixed {
		return null;
	}

	public function singleQuery(string $query): ?array {
		return null;
	}

	public function &listQuery(string $query): array {
		$result = [];
		return $result;
	}

	public function &multiQuery(string $query): array {
		$result = [];
		return $result;
	}

	public function affectedRows(): int {
		return 0;
	}

	public function insertId(): int|string {
		return 0;
	}

	public function escape(string $str): string {
		return addslashes($str);
	}

	public function isError(): bool {
		return false;
	}

	public function errorNumber(): int {
		return 0;
	}

	public function errorMessage(): string {
		return '';
	}
}
