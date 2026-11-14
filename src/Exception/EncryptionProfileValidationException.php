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

namespace Base3\KeyHarbor\Exception;

use InvalidArgumentException;

/**
 * Describes a rejected encryption administration input using a stable translation reason.
 * The technical message remains available to diagnostic callers; no secret values are included.
 */
final class EncryptionProfileValidationException extends InvalidArgumentException {

	/**
	 * @param string $reason Stable lower-case reason identifier for plugin-owned UI translations
	 * @param string $message Technical description without sensitive key material
	 * @return void
	 * @throws \InvalidArgumentException Never by itself; this object represents the failure
	 */
	public function __construct(private readonly string $reason, string $message) {
		parent::__construct($message);
	}

	/**
	 * Returns the stable, untranslated validation reason for localization by KeyHarbor displays.
	 *
	 * @return string Non-empty lower-case reason, not the original user input
	 */
	public function getReason(): string {
		return $this->reason;
	}
}
