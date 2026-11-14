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

namespace Base3\KeyHarbor\Test\Repository;

use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\KeyHarbor\Model\ApiCredential;
use Base3\KeyHarbor\Repository\DatabaseCredentialRepository;
use PHPUnit\Framework\TestCase;

final class RepositoryTestDatabase implements IDatabase {

	/** @var array<int,string> */
	public array $queries = [];
	/** @var array<int,string> */
	public array $singleQueries = [];
	/** @var array<int,array<string,mixed>|null> */
	public array $singleResponses = [];
	/** @var array<int,array<int,array<string,mixed>>> */
	public array $multiResponses = [];
	/** @var array<int,string> */
	public array $multiQueries = [];
	/** @var array<int,mixed> */
	public array $scalarResponses = [];
	/** @var array<int,string> */
	public array $scalarQueries = [];
	public int $beginCount = 0;
	public int $commitCount = 0;
	public int $rollbackCount = 0;
	public int $affectedRowsCount = 0;
	public int $affectedRowsResult = 1;
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

	public function beginTransaction(): void {
		$this->beginCount++;
	}

	public function commit(): void {
		$this->commitCount++;
	}

	public function rollback(): void {
		$this->rollbackCount++;
	}

	public function nonQuery(string $query): void {
		$this->queries[] = $query;
	}

	public function scalarQuery(string $query): mixed {
		$this->scalarQueries[] = $query;
		return array_shift($this->scalarResponses);
	}

	public function singleQuery(string $query): ?array {
		$this->singleQueries[] = $query;
		return array_shift($this->singleResponses);
	}

	public function &listQuery(string $query): array {
		$result = [];
		return $result;
	}

	public function &multiQuery(string $query): array {
		$this->multiQueries[] = $query;
		$result = array_shift($this->multiResponses) ?? [];
		return $result;
	}

	public function affectedRows(): int {
		$this->affectedRowsCount++;
		return $this->affectedRowsResult;
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
