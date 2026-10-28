<?php declare(strict_types=1);

/** Exercise versioned grant data and lifecycle without a running database. */

use CredentialFoundation\Dto\CredentialSubject;
use KeyHarbor\Model\ApiCredential;

$root = dirname(__DIR__, 4);
if (!defined('DIR_ROOT')) define('DIR_ROOT', $root . DIRECTORY_SEPARATOR);
if (!defined('DIR_SRC')) define('DIR_SRC', DIR_ROOT . 'src' . DIRECTORY_SEPARATOR);
if (!defined('DIR_PLUGIN')) define('DIR_PLUGIN', DIR_ROOT . 'plugin' . DIRECTORY_SEPARATOR);
if (!defined('DIR_TEST')) define('DIR_TEST', DIR_ROOT . 'test' . DIRECTORY_SEPARATOR);
require_once DIR_SRC . 'Core/Autoloader.php';
Base3\Core\Autoloader::register();

$policy = ['version' => 1, 'expression' => ['kind' => 'compare', 'attribute' => 'type', 'operator' => 'eq', 'value' => 'project']];
$subject = new CredentialSubject('service', 'contourz');
$make = static fn(array $constraints): ApiCredential => new ApiCredential(
 str_repeat('a', 32), str_repeat('b', 20), '42', 'admin', 'Admin', '', 'en', 'Contourz',
 str_repeat('c', 64), true, 'encrypted', 'nonce', 1000, null, null, null, null,
 ['entitydatamicroservice'], $subject,
 ['entitydatamicroservice' => ['getEntries', 'getEntry']], $constraints
);
$constraints = ['entitydatamicroservice' => ['getEntries' => $policy]];
$credential = $make($constraints);
if ($credential->getResourceConstraint('entitydatamicroservice', 'getEntries') !== $policy) throw new RuntimeException('Missing policy');
if ($credential->getResourceConstraint('entitydatamicroservice', 'getEntry') !== null) throw new RuntimeException('Ungrantable implicit scope');
foreach ([$credential->withRotatedSecret(str_repeat('d', 20), str_repeat('e', 64), 'encrypted2', 'nonce2'), $credential->withRevokedAt(2000), $credential->withManagementData('Renamed', '', 'en', null, ['entitydatamicroservice'])] as $copy) {
 if ($copy->getResourceConstraints() !== $constraints) throw new RuntimeException('Lifecycle lost policy');
}
foreach ([['missing' => ['getEntries' => $policy]], ['entitydatamicroservice' => ['deleteEntry' => $policy]]] as $bad) {
 try { $make($bad); throw new RuntimeException('Rejected invalid policy silently'); }
 catch (InvalidArgumentException $expected) { }
}
$legacy = $make([]);
if ($legacy->getResourceConstraints() !== []) throw new RuntimeException('Legacy default must stay empty');
echo "Resource grant lifecycle: OK (4 cases)\n";
