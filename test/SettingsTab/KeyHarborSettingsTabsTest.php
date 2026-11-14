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
use Base3\CoreFoundation\Translation\Api\ITranslation;
use Base3\KeyHarbor\SettingsTab\CredentialsSettingsTab;
use Base3\KeyHarbor\SettingsTab\EncryptionprofilesSettingsTab;
use Base3\KeyHarbor\SettingsTab\KeyharboradminSettingsTab;
use Base3\KeyHarbor\SettingsTab\KeyharborserviceadminSettingsTab;
use Base3\KeyHarbor\SettingsTab\KeymanagementSettingsTab;
use PHPUnit\Framework\TestCase;

final class KeyHarborSettingsTabsTest extends TestCase {

	public function testCredentialsRootHasNoDisplayOrParent(): void {
		$root = new CredentialsSettingsTab($this->createStub(ITranslation::class));

		self::assertSame('credentialssettingstab', CredentialsSettingsTab::getName());
		self::assertSame(110, $root->getOrder());
		self::assertNull($root->getParentTabName());
		self::assertNull($root->getDisplayName());
		self::assertNull($root->getDisplayData());
		self::assertTrue($root->isVisible());
	}

	public function testChildTabsHaveStableNamesDisplaysAndOrders(): void {
		foreach ([
			[KeymanagementSettingsTab::class, 'keymanagementdisplay', 10],
			[KeyharboradminSettingsTab::class, 'keyharboradmindisplay', 20],
			[KeyharborserviceadminSettingsTab::class, 'keyharborserviceadmindisplay', 30],
			[EncryptionprofilesSettingsTab::class, 'encryptionprofilesdisplay', 40]
		] as [$type, $display, $order]) {
			$tab = new $type($this->createStub(ITranslation::class), $this->createStub(IAuthorizationService::class));
			self::assertSame(strtolower(substr(strrchr($type, '\\'), 1)), $type::getName());
			self::assertSame('credentialssettingstab', $tab->getParentTabName());
			self::assertSame($display, $tab->getDisplayName());
			self::assertSame($order, $tab->getOrder());
			self::assertNull($tab->getDisplayData());
		}
	}

	public function testPersonalCredentialTabUsesAuthenticatedAudience(): void {
		$tab = new KeymanagementSettingsTab($this->createStub(ITranslation::class), $this->createStub(IAuthorizationService::class));
		self::assertSame(['criterion' => 'base3authenticatedcriterion', 'config' => []], $tab->getDefaultAudience());
	}

	public function testAdministrationTabsDoNotInheritPersonalCredentialAudience(): void {
		foreach ([KeyharboradminSettingsTab::class, KeyharborserviceadminSettingsTab::class, EncryptionprofilesSettingsTab::class] as $type) {
			$tab = new $type($this->createStub(ITranslation::class), $this->createStub(IAuthorizationService::class));
			self::assertSame(['group' => 'administration'], $tab->getDefaultAudience());
		}
	}

	public function testEveryChildDeclaresItsOwnFeaturePermission(): void {
		foreach ([KeymanagementSettingsTab::class, KeyharboradminSettingsTab::class, KeyharborserviceadminSettingsTab::class, EncryptionprofilesSettingsTab::class] as $type) {
			$tab = new $type($this->createStub(ITranslation::class), $this->createStub(IAuthorizationService::class));
			$permissions = $tab->getPermissions();
			self::assertCount(1, $permissions);
			self::assertSame('feature', $permissions[0]->scope);
			self::assertSame($type::getName(), $permissions[0]->permission);
		}
	}
}
