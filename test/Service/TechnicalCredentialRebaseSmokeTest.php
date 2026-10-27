<?php declare(strict_types=1);

/** Standalone smoke test of user/service credentials and managed-encryption contracts. */

use Base3\Api\IContainer;
use CredentialFoundation\Api\IApiCredentialService;
use CredentialFoundation\Api\ICredentialOperationAccess;
use CredentialFoundation\Api\IEncryptionProfileService;
use CredentialFoundation\Dto\CredentialIdentityResult;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use CredentialFoundation\Dto\CredentialSubject;
use KeyHarbor\KeyHarborPlugin;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Service\ApiCredentialService;
use KeyHarbor\Service\EncryptionProfileService;

$root = dirname(__DIR__, 4);
if (!defined('DIR_ROOT')) define('DIR_ROOT', $root . DIRECTORY_SEPARATOR);
if (!defined('DIR_SRC')) define('DIR_SRC', DIR_ROOT . 'src' . DIRECTORY_SEPARATOR);
if (!defined('DIR_TEST')) define('DIR_TEST', DIR_ROOT . 'test' . DIRECTORY_SEPARATOR);
if (!defined('DIR_PLUGIN')) define('DIR_PLUGIN', DIR_ROOT . 'plugin' . DIRECTORY_SEPARATOR);
require_once DIR_SRC . 'Core/Autoloader.php';
Base3\Core\Autoloader::register();

function verifyRebase(bool $condition, string $message): void {
	if (!$condition) throw new RuntimeException($message);
}

$service = new CredentialServiceDefinition('entitydatamicroservice', 'XRM data', '', ['getEntries', 'getEntry']);
verifyRebase($service->getOperations() === ['getEntries', 'getEntry'], 'Operation declaration must be normalized.');

$subject = new CredentialSubject(CredentialSubject::TYPE_SERVICE, 'contourz');
verifyRebase($subject->isService() && !$subject->isUser(), 'Technical principal must not be a user.');

$base = [
	str_repeat('a', 32), str_repeat('b', 20), '42', 'admin', 'Administrator',
	'', 'en', 'Contourz', str_repeat('c', 64), true, 'encrypted-hmac', 'nonce',
	time(), null, null, null, null, ['entitydatamicroservice']
];
$technical = new ApiCredential(...[...$base, $subject, ['entitydatamicroservice' => ['getEntries', 'getEntry']]]);
verifyRebase($technical->getSubject()->getId() === 'contourz', 'Principal must be stable.');
verifyRebase($technical->hasOperation('entitydatamicroservice', 'getEntries'), 'Granted method must be allowed.');
verifyRebase(!$technical->hasOperation('entitydatamicroservice', 'deleteEntry'), 'Write method must be denied.');
verifyRebase(!$technical->hasOperation('unknownservice', 'getEntries'), 'Unknown service must be denied.');

$identity = CredentialIdentityResult::success('key-id', null, null, $subject);
verifyRebase($identity->isAuthenticated() && $identity->getUserId() === null, 'Service must never inherit issuer user ID.');

$legacy = $base;
$legacy[9] = false;
$legacy[10] = null;
$legacy[11] = null;
$userCredential = new ApiCredential(...$legacy);
verifyRebase($userCredential->getSubject()->isUser(), 'Legacy credential must remain a user credential.');
verifyRebase($userCredential->hasOperation('entitydatamicroservice', 'getEntries'), 'User grants must remain compatible.');

verifyRebase(is_subclass_of(ApiCredentialService::class, ICredentialOperationAccess::class), 'KeyHarbor must implement operation authorization.');
verifyRebase(is_subclass_of(EncryptionProfileService::class, IEncryptionProfileService::class), 'New encryption service must remain available.');

// Verify that the new managed encryption and technical service capabilities are
// registered together instead of one alias overwriting the other.
$container = new class implements IContainer {
	private array $definitions = [];
	public function getServiceList(): array { return array_keys($this->definitions); }
	public function set(string $name, $classDefinition, $flags = 0): IContainer {
		$this->definitions[$name] = [$classDefinition, $flags];
		return $this;
	}
	public function remove(string $name) { unset($this->definitions[$name]); }
	public function has(string $name): bool { return array_key_exists($name, $this->definitions); }
	public function get(string $name) { return $this->definitions[$name][0] ?? null; }
};
(new KeyHarborPlugin($container))->init();
verifyRebase($container->get(ICredentialOperationAccess::class) === IApiCredentialService::class, 'Service-operation alias must remain registered.');
verifyRebase($container->get(IEncryptionProfileService::class) === EncryptionProfileService::class, 'Managed-encryption alias must remain registered.');
verifyRebase($container->has(EncryptionProfileService::class), 'Managed-encryption provider must remain registered.');

echo "KeyHarbor service identity and encryption compatibility: OK\n";
