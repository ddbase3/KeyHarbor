<?php declare(strict_types=1);

namespace KeyHarbor\Service;

use CredentialFoundation\Dto\CredentialServiceDefinition;
use InvalidArgumentException;

/**
 * Validates a versioned, declarative resource predicate against service-owned metadata.
 * Never generates backend queries or interprets application-specific attributes.
 */
final class ResourceConstraintValidator {

	private const MAX_NODES = 64;
	private const MAX_DEPTH = 8;
	private const MAX_VALUES = 32;

	/**
	 * Validate one complete resource expression. An absent expression is not a grant.
	 *
	 * @param array<string,mixed> $definition Version 1 expression with a root node.
	 * @param CredentialServiceDefinition $service Declaring service metadata.
	 * @return array<string,mixed> The validated expression, without semantic rewriting.
	 * @throws InvalidArgumentException On unknown version, operator, attribute, value, or complexity.
	 */
	public function validate(array $definition, CredentialServiceDefinition $service): array {
		if (count($definition) !== 2 || !array_key_exists('version', $definition) || !array_key_exists('expression', $definition) || ($definition['version'] ?? null) !== 1 || !is_array($definition['expression'])) {
			throw new InvalidArgumentException('Invalid resource expression envelope.');
		}
		$count = 0;
		$this->validateNode($definition['expression'], $service->getResourceAttributes(), 1, $count);
		return $definition;
	}

	private function validateNode(array $node, array $attributes, int $depth, int &$count): void {
		if (++$count > self::MAX_NODES || $depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('Resource expression complexity exceeded.');
		}
		$kind = $node['kind'] ?? null;
		if ($kind === 'all' || $kind === 'any') {
			if (count($node) !== 2 || !array_key_exists('nodes', $node) || !is_array($node['nodes']) || !array_is_list($node['nodes']) || $node['nodes'] === [] || count($node['nodes']) > self::MAX_VALUES) {
				throw new InvalidArgumentException('Invalid resource group.');
			}
			foreach ($node['nodes'] as $child) {
				if (!is_array($child)) throw new InvalidArgumentException('Invalid resource child.');
				$this->validateNode($child, $attributes, $depth + 1, $count);
			}
			return;
		}
		if ($kind === 'not') {
			if (count($node) !== 2 || !isset($node['node']) || !is_array($node['node'])) throw new InvalidArgumentException('Invalid negation.');
			$this->validateNode($node['node'], $attributes, $depth + 1, $count);
			return;
		}
		if ($kind !== 'compare' || count($node) !== 4 || !isset($node['attribute'], $node['operator'], $node['value'])) {
			throw new InvalidArgumentException('Invalid resource comparison.');
		}
		$attribute = $node['attribute'];
		$operator = $node['operator'];
		if (!is_string($attribute) || !is_string($operator) || !in_array($operator, $attributes[$attribute] ?? [], true)) {
			throw new InvalidArgumentException('Undeclared resource attribute or operator.');
		}
		$value = $node['value'];
		if (in_array($operator, ['in', 'not_in'], true)) {
			if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > self::MAX_VALUES) {
				throw new InvalidArgumentException('Invalid resource set.');
			}
			foreach ($value as $member) $this->assertScalar($member);
		} else {
			$this->assertScalar($value);
		}
	}

	private function assertScalar(mixed $value): void {
		if ((!is_string($value) && !is_int($value) && !is_bool($value)) || (is_string($value) && (strlen($value) > 256 || $value === ''))) {
			throw new InvalidArgumentException('Invalid resource comparison value.');
		}
	}
}
