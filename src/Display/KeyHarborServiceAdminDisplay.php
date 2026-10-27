<?php declare(strict_types=1);

namespace KeyHarbor\Display;

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

	protected function isServiceOnly(): bool {
		return true;
	}
}
