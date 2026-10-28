<?php declare(strict_types=1);

use Base3\Database\Api\IDatabase;
use KeyHarbor\Migration\Migration001CreateCredentialTables;
use KeyHarbor\Migration\Migration002CredentialSubjects;
use KeyHarbor\Migration\Migration003ResourceConstraints;

$root = dirname(__DIR__, 4);
if (!defined('DIR_ROOT')) define('DIR_ROOT', $root . DIRECTORY_SEPARATOR);
if (!defined('DIR_SRC')) define('DIR_SRC', DIR_ROOT . 'src' . DIRECTORY_SEPARATOR);
if (!defined('DIR_PLUGIN')) define('DIR_PLUGIN', DIR_ROOT . 'plugin' . DIRECTORY_SEPARATOR);
if (!defined('DIR_TEST')) define('DIR_TEST', DIR_ROOT . 'test' . DIRECTORY_SEPARATOR);
require_once DIR_SRC . 'Core/Autoloader.php';
Base3\Core\Autoloader::register();

final class ResourceMigrationRecordingDatabase implements IDatabase {
    public array $queries = [];
    public bool $isConnected = false;
    public function connect(): void { $this->isConnected = true; }
    public function disconnect(): void { $this->isConnected = false; }
    public function connected(): bool { return $this->isConnected; }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function nonQuery(string $query): void { $this->queries[] = $query; }
    public function scalarQuery(string $query): mixed { return null; }
    public function singleQuery(string $query): ?array { return null; }
    public function &listQuery(string $query): array { $result = []; return $result; }
    public function &multiQuery(string $query): array { $result = []; return $result; }
    public function affectedRows(): int { return 0; }
    public function insertId(): int|string { return 0; }
    public function escape(string $str): string { return addslashes($str); }
    public function isError(): bool { return false; }
    public function errorNumber(): int { return 0; }
    public function errorMessage(): string { return ''; }
}

$database = new ResourceMigrationRecordingDatabase();
foreach ([Migration001CreateCredentialTables::class, Migration002CredentialSubjects::class, Migration003ResourceConstraints::class] as $class) {
    (new $class($database))->up();
}
$queries = $database->queries;
if (!$database->connected() || count($queries) !== 6) throw new RuntimeException('Unexpected migration sequence.');
$expected = [
    'CREATE TABLE base3_keyharbor_key',
    'CREATE TABLE base3_keyharbor_grant',
    'ALTER TABLE base3_keyharbor_key ADD COLUMN subject_type',
    'UPDATE base3_keyharbor_key SET subject_id=owner_user_id',
    'ALTER TABLE base3_keyharbor_grant ADD COLUMN operations_json TEXT NULL',
    'ALTER TABLE base3_keyharbor_grant ADD COLUMN constraints_json TEXT NULL'
];
foreach ($expected as $i => $fragment) {
    if (!str_contains($queries[$i], $fragment)) {
        throw new RuntimeException('Migration SQL mismatch at statement ' . ($i + 1));
    }
}
if (Migration001CreateCredentialTables::getName() === Migration003ResourceConstraints::getName()) {
    throw new RuntimeException('Migration names must be unique.');
}
echo "KeyHarbor migration 001-003 SQL sequence: OK (6 statements, recording database only)\n";
