<?php declare(strict_types=1);

namespace KeyHarbor\Test\Migration;

use Base3\Database\Api\IDatabase;
use KeyHarbor\Migration\Migration001CreateCredentialTables;
use KeyHarbor\Migration\Migration002CredentialSubjects;
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
