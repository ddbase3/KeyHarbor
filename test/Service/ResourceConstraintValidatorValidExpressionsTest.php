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

namespace Base3\KeyHarbor\Test\Service;

use Base3\CredentialFoundation\Dto\CredentialServiceDefinition;
use InvalidArgumentException;
use Base3\KeyHarbor\Service\ResourceConstraintValidator;
use PHPUnit\Framework\TestCase;

final class ResourceConstraintValidatorValidExpressionsTest extends TestCase {

	public function testPreservesValidScalarComparisonsWithoutRewritingValues(): void {
		$validator = new ResourceConstraintValidator();
		foreach (['tenant-1', 42, true, false, 0] as $value) {
			$expression = $this->envelope($this->compare('eq', $value));
			self::assertSame($expression, $validator->validate($expression, $this->service()));
		}
	}

	public function testAcceptsNestedConjunctionDisjunctionAndNegation(): void {
		$validator = new ResourceConstraintValidator();
		$expression = $this->envelope([
			'kind' => 'all',
			'nodes' => [
				$this->compare('eq', 'tenant-1'),
				['kind' => 'any', 'nodes' => [
					$this->compare('eq', 10),
					['kind' => 'not', 'node' => $this->compare('eq', false)]
				]]
			]
		]);

		self::assertSame($expression, $validator->validate($expression, $this->service()));
	}

	public function testAcceptsNonemptyInAndNotInSetsWithTypedMembers(): void {
		foreach (['in', 'not_in'] as $operator) {
			$expression = $this->envelope($this->compare($operator, ['tenant-1', 42, true, false]));
			self::assertSame($expression, (new ResourceConstraintValidator())->validate($expression, $this->service()));
		}
	}

	public function testMaximumAllowedSetSizeIsAccepted(): void {
		$expression = $this->envelope($this->compare('in', range(1, 32)));
		self::assertSame($expression, (new ResourceConstraintValidator())->validate($expression, $this->service()));
	}

	public function testSetWithThirtyThreeValuesIsRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid resource set.');
		(new ResourceConstraintValidator())->validate($this->envelope($this->compare('in', range(1, 33))), $this->service());
	}

	public function testExactlyEightLevelsOfNestingAreAccepted(): void {
		$node = $this->compare('eq', 'tenant-1');
		for ($level = 1; $level < 8; $level++) {
			$node = ['kind' => 'not', 'node' => $node];
		}
		$expression = $this->envelope($node);
		self::assertSame($expression, (new ResourceConstraintValidator())->validate($expression, $this->service()));
	}

	public function testExactlySixtyFourNodesAreAccepted(): void {
		$node = ['kind' => 'all', 'nodes' => [
			['kind' => 'all', 'nodes' => array_fill(0, 30, $this->compare('eq', 1))],
			['kind' => 'any', 'nodes' => array_fill(0, 31, $this->compare('eq', 2))]
		]];
		$expression = $this->envelope($node);
		self::assertSame($expression, (new ResourceConstraintValidator())->validate($expression, $this->service()));
	}

	public function testSixtyFiveNodesAreRejectedEvenWhenEachGroupIsValid(): void {
		$node = ['kind' => 'all', 'nodes' => [
			['kind' => 'all', 'nodes' => array_fill(0, 31, $this->compare('eq', 1))],
			['kind' => 'any', 'nodes' => array_fill(0, 31, $this->compare('eq', 2))]
		]];
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Resource expression complexity exceeded.');
		(new ResourceConstraintValidator())->validate($this->envelope($node), $this->service());
	}

	public function testRejectedComparisonValuesCannotBeArraysFloatsOrNull(): void {
		foreach ([[], ['tenant-1'], 1.5, null, new \stdClass(), '', str_repeat('a', 257)] as $value) {
			try {
				(new ResourceConstraintValidator())->validate($this->envelope($this->compare('eq', $value)), $this->service());
				self::fail('Scalar resource comparisons must not accept unsupported values.');
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('Invalid resource', $exception->getMessage());
			}
		}
	}

	public function testSetMembersRejectInvalidScalarsAndTooLongStrings(): void {
		foreach ([1.5, null, [], str_repeat('x', 257), ''] as $value) {
			try {
				(new ResourceConstraintValidator())->validate($this->envelope($this->compare('in', [$value])), $this->service());
				self::fail('Invalid resource set member was accepted.');
			} catch (InvalidArgumentException $exception) {
				self::assertSame('Invalid resource comparison value.', $exception->getMessage());
			}
		}
	}

	public function testUndeclaredOperatorsAreDeniedEvenWhenTheyLookEquivalent(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Undeclared resource attribute or operator.');
		(new ResourceConstraintValidator())->validate($this->envelope($this->compare('contains', 'tenant-1')), $this->service());
	}

	private function service(): CredentialServiceDefinition {
		return new CredentialServiceDefinition('example:read', 'Example read', '', ['getEntries'], [
			'tenant' => ['eq', 'in', 'not_in']
		]);
	}

	private function envelope(array $expression): array {
		return ['version' => 1, 'expression' => $expression];
	}

	private function compare(string $operator, mixed $value): array {
		return ['kind' => 'compare', 'attribute' => 'tenant', 'operator' => $operator, 'value' => $value];
	}
}
