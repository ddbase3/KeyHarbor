<?php declare(strict_types=1);

namespace KeyHarbor\SettingsTab;

use UiFoundation\Settings\AbstractFeatureSettingsTab;

final class KeymanagementSettingsTab extends AbstractFeatureSettingsTab {

	public function getParentTabName(): ?string {
		return 'credentialssettingstab';
	}

	public function getDisplayName(): ?string {
		return 'keymanagementdisplay';
	}

	public function getOrder(): int {
		return 10;
	}

	public function getDefaultAudience(): array {
		return ['criterion' => 'base3authenticatedcriterion', 'config' => []];
	}
}
