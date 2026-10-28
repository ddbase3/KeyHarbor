<?php declare(strict_types=1);

require_once __DIR__ . '/../../../CredentialFoundation/src/Dto/CredentialServiceDefinition.php';
require_once __DIR__ . '/../../src/Service/ResourceConstraintValidator.php';

use CredentialFoundation\Dto\CredentialServiceDefinition;
use KeyHarbor\Service\ResourceConstraintValidator;

$service = new CredentialServiceDefinition('sample', 'Sample', '', ['getEntries'], [
	'type' => ['eq'], 'tag' => ['has', 'not_has']
]);
$validator = new ResourceConstraintValidator();
$compare = fn(string $field, string $operator, string $value): array => [
	'kind' => 'compare', 'attribute' => $field, 'operator' => $operator, 'value' => $value
];
$expression = ['version' => 1, 'expression' => ['kind' => 'any', 'nodes' => [
	['kind' => 'all', 'nodes' => [$compare('type', 'eq', 'project'), $compare('tag', 'has', 'public')]],
	['kind' => 'not', 'node' => $compare('tag', 'has', 'secret')]
]]];
assert($validator->validate($expression, $service) === $expression);
$invalid = [
	['version' => 2, 'expression' => $compare('type', 'eq', 'project')],
	['version' => 1, 'expression' => $compare('other', 'eq', 'project')],
	['version' => 1, 'expression' => $compare('type', 'has', 'project')],
	['version' => 1, 'expression' => ['kind' => 'any', 'nodes' => []]],
	['version' => 1, 'expression' => ['kind' => 'unknown', 'nodes' => [$compare('type', 'eq', 'project')]]]
];

$deep = $compare('type', 'eq', 'project');
for ($i = 0; $i < 9; $i++) $deep = ['kind' => 'not', 'node' => $deep];
$invalid[] = ['version' => 1, 'expression' => $deep];
foreach ($invalid as $i => $definition) {
	try {
		$validator->validate($definition, $service);
		throw new RuntimeException('Invalid definition passed: ' . $i);
	} catch (InvalidArgumentException) {
	}
}
echo "Resource constraint validation: OK (1 valid, " . count($invalid) . " rejected)\n";
