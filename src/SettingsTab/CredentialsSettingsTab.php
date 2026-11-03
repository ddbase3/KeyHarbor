<?php declare(strict_types=1);

namespace KeyHarbor\SettingsTab;

use UiFoundation\Settings\AbstractSettingsTab;

final class CredentialsSettingsTab extends AbstractSettingsTab {

	public function getOrder(): int {
		return 110;
	}
}
