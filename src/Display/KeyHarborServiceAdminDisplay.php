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

namespace Base3\KeyHarbor\Display;

/**
 * Dedicated administrator view for technical service credentials.
 *
 * Shares the existing KeyHarbor admin endpoint, access checks, service credential
 * lifecycle, translations, template and ClientStack dialogs without a second
 * implementation of credential management.
 */
final class KeyHarborServiceAdminDisplay extends KeyHarborAdminDisplay {

	public static function getName(): string {
		return 'keyharborserviceadmindisplay';
	}

	protected function settingsTabFeatureName(): string {
		return \Base3\KeyHarbor\SettingsTab\KeyharborserviceadminSettingsTab::getName();
	}

	protected function isServiceOnly(): bool {
		return true;
	}
}
