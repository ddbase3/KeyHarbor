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

namespace Base3\KeyHarbor\Dto;

/**
 * Parsed public and secret parts of a syntactically valid credential token.
 */
final class ParsedCredentialToken {

	public function __construct(
		private readonly string $publicId,
		private readonly string $secret
	) {}

	public function getPublicId(): string {
		return $this->publicId;
	}

	public function getSecret(): string {
		return $this->secret;
	}
}
