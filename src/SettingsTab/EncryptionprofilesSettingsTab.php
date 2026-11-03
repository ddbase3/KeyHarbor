<?php declare(strict_types=1);

namespace KeyHarbor\SettingsTab;

use UiFoundation\Settings\AbstractFeatureSettingsTab;

final class EncryptionprofilesSettingsTab extends AbstractFeatureSettingsTab {

	public function getParentTabName(): ?string {
		return 'credentialssettingstab';
	}

	public function getDisplayName(): ?string {
		return 'encryptionprofilesdisplay';
	}

	public function getOrder(): int {
		return 40;
	}
}
