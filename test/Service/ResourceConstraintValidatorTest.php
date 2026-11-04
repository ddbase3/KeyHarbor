<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use CredentialFoundation\Dto\CredentialServiceDefinition;
use InvalidArgumentException;
use KeyHarbor\Service\ResourceConstraintValidator;
use PHPUnit\Framework\TestCase;

final class ResourceConstraintValidatorTest extends TestCase {

	public function testRejectsMissingAndUnexpectedEnvelopeFields(): void {
		foreach ([
			[],
			['version' => 1],
			['expression' => []],
			['version' => 2, 'expression' => []],
			['version' => '1', 'expression' => []],
			['version' => 1, 'expression' => 'compare'],
			['version' => 1, 'expression' => [], 'extra' => true]
		] as $invalid) {
			$this->assertRejected($invalid, 'Invalid resource expression envelope.');
		}
	}

	public function testRejectsEmptyGroupAndUnexpectedFields(): void {
		foreach ([
			['kind' => 'all', 'nodes' => []],
			['kind' => 'any', 'nodes' => []],
			['kind' => 'all', 'nodes' => 'child'],
			['kind' => 'any', 'nodes' => [], 'extra' => true],
			['kind' => 'all', 'nodes' => [0 => [], 2 => []]],
			['kind' => 'any', 'nodes' => array_fill(0, 33, [])]
		] as $invalid) {
			$this->assertRejected($this->envelope($invalid), 'Invalid resource group.');
		}
	}

	public function testRejectsNonObjectGroupChildren(): void {
		foreach (['not-an-object', null, true, 4] as $invalid) {
			$this->assertRejected($this->envelope(['kind' => 'all', 'nodes' => [$invalid]]), 'Invalid resource child.');
		}
	}

	public function testRejectsMalformedNegation(): void {
		foreach ([
			['kind' => 'not'],
			['kind' => 'not', 'node' => 'compare'],
			['kind' => 'not', 'node' => null],
			['kind' => 'not', 'node' => [], 'extra' => 1]
		] as $invalid) {
			$this->assertRejected($this->envelope($invalid), 'Invalid negation.');
		}
	}

	public function testRejectsUnknownNodeKindsAndIncompleteComparisons(): void {
		foreach ([
			[],
			['kind' => 'either', 'nodes' => [[]]],
			['kind' => 'compare'],
			['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq'],
			['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq', 'value' => 'x', 'extra' => true]
		] as $invalid) {
			$this->assertRejected($this->envelope($invalid), 'Invalid resource comparison.');
		}
	}

	public function testRejectsUndeclaredAttributesAndOperators(): void {
		foreach ([
			['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq', 'value' => '42'],
			['kind' => 'compare', 'attribute' => '', 'operator' => 'in', 'value' => ['42']],
			['kind' => 'compare', 'attribute' => ['tenant'], 'operator' => 'eq', 'value' => 1],
			['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 5, 'value' => 1]
		] as $invalid) {
			$this->assertRejected($this->envelope($invalid), 'Undeclared resource attribute or operator.');
		}
	}

	public function testRejectsDepthAboveEightLevels(): void {
		$node = ['kind' => 'compare', 'attribute' => 'tenant', 'operator' => 'eq', 'value' => '42'];
		for ($i = 0; $i < 8; $i++) {
			$node = ['kind' => 'not', 'node' => $node];
		}
		$this->assertRejected($this->envelope($node), 'Resource expression complexity exceeded.');
	}

	private function envelope(array $node): array {
		return ['version' => 1, 'expression' => $node];
	}

	private function assertRejected(array $definition, string $expectedMessage): void {
		$validator = new ResourceConstraintValidator();
		$service = new CredentialServiceDefinition('example:read', 'Example read');
		try {
			$validator->validate($definition, $service);
			self::fail('Expected invalid resource constraint to be rejected.');
		} catch (InvalidArgumentException $exception) {
			self::assertSame($expectedMessage, $exception->getMessage());
		}
	}
}
