<?php declare(strict_types=1);

namespace KeyHarbor\SettingsTab;

use UiFoundation\Settings\AbstractFeatureSettingsTab;

final class KeyharboradminSettingsTab extends AbstractFeatureSettingsTab {

	public function getParentTabName(): ?string {
		return 'credentialssettingstab';
	}

	public function getDisplayName(): ?string {
		return 'keyharboradmindisplay';
	}

	public function getOrder(): int {
		return 20;
	}
}
