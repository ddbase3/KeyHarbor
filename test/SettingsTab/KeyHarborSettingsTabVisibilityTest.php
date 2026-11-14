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

namespace Base3\KeyHarbor\Test\SettingsTab;

use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationDecision;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationRequest;
use Base3\CoreFoundation\Translation\Api\ITranslation;
use Base3\KeyHarbor\SettingsTab\CredentialsSettingsTab;
use Base3\KeyHarbor\SettingsTab\EncryptionprofilesSettingsTab;
use Base3\KeyHarbor\SettingsTab\KeyharboradminSettingsTab;
use Base3\KeyHarbor\SettingsTab\KeyharborserviceadminSettingsTab;
use Base3\KeyHarbor\SettingsTab\KeymanagementSettingsTab;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KeyHarborSettingsTabVisibilityTest extends TestCase {

	public function testAllTabLabelsAndDescriptionsUseTheirOwnTranslationKeys(): void {
		$translation = $this->createStub(ITranslation::class);
		$translation->method('translate')->willReturnCallback(
			static fn(string $set, string $section, string $key): string => $set . ':' . $section . ':' . $key
		);
		$authorization = $this->authorization(AuthorizationDecision::deny());
		foreach ($this->tabNames() as $class => $name) {
			$tab = $class === CredentialsSettingsTab::class
				? new $class($translation)
				: new $class($translation, $authorization);
			self::assertSame('Settings:' . $name . ':label', $tab->getLabel());
			self::assertSame('Settings:' . $name . ':description', $tab->getDescription());
		}
	}

	public function testFeatureVisibilityRequiresExplicitAllow(): void {
		foreach ([AuthorizationDecision::allow(), AuthorizationDecision::deny(), AuthorizationDecision::indeterminate()] as $decision) {
			$authorization = $this->authorization($decision);
			foreach (array_keys($this->tabNames()) as $class) {
				if ($class === CredentialsSettingsTab::class) {
					continue;
				}
				$tab = new $class($this->createStub(ITranslation::class), $authorization);
				self::assertSame($decision->isAllowed(), $tab->isVisible());
			}
		}
	}

	public function testEachFeatureVisibilityChecksTheOwningPermissionAndProjectAuthority(): void {
		foreach ($this->tabNames() as $class => $name) {
			if ($class === CredentialsSettingsTab::class) {
				continue;
			}
			$requests = [];
			$authorization = $this->createStub(IAuthorizationService::class);
			$authorization->method('getDefaultAuthority')->willReturn('configured-host');
			$authorization->method('decide')->willReturnCallback(
				static function(AuthorizationRequest $request) use (&$requests): AuthorizationDecision {
					$requests[] = $request;
					return AuthorizationDecision::allow();
				}
			);
			$tab = new $class($this->createStub(ITranslation::class), $authorization);
			self::assertTrue($tab->isVisible());
			self::assertCount(1, $requests);
			self::assertSame('configured-host', $requests[0]->authority);
			self::assertSame('feature', $requests[0]->permission->scope);
			self::assertSame($name, $requests[0]->permission->permission);
		}
	}

	public function testAuthorizationErrorsFailClosedForEveryFeatureTab(): void {
		$authorization = $this->createStub(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('configured-host');
		$authorization->method('decide')->willReturnCallback(
			static function(): AuthorizationDecision { throw new RuntimeException('Authorization backend unavailable.'); }
		);
		foreach (array_keys($this->tabNames()) as $class) {
			if ($class !== CredentialsSettingsTab::class) {
				self::assertFalse((new $class($this->createStub(ITranslation::class), $authorization))->isVisible());
			}
		}
	}

	public function testCredentialsRootRemainsNavigationOnlyAndHasNoFeaturePermission(): void {
		$tab = new CredentialsSettingsTab($this->createStub(ITranslation::class));
		self::assertTrue($tab->isVisible());
		self::assertNull($tab->getParentTabName());
		self::assertNull($tab->getDisplayName());
		self::assertNull($tab->getDisplayData());
	}

	private function authorization(AuthorizationDecision $decision): IAuthorizationService {
		$service = $this->createStub(IAuthorizationService::class);
		$service->method('getDefaultAuthority')->willReturn('configured-host');
		$service->method('decide')->willReturn($decision);
		return $service;
	}

	/** @return array<class-string,string> */
	private function tabNames(): array {
		return [
			CredentialsSettingsTab::class => 'credentialssettingstab',
			KeymanagementSettingsTab::class => 'keymanagementsettingstab',
			KeyharboradminSettingsTab::class => 'keyharboradminsettingstab',
			KeyharborserviceadminSettingsTab::class => 'keyharborserviceadminsettingstab',
			EncryptionprofilesSettingsTab::class => 'encryptionprofilessettingstab'
		];
	}
}
