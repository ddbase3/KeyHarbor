<?php declare(strict_types=1);

namespace KeyHarbor\Dto;

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
