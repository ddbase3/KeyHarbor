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

namespace Base3\KeyHarbor\Test\Accesscontrol;

use Base3\CoreFoundation\Api\IRequest;
use Base3\CredentialFoundation\Api\ICredentialAccess;
use Base3\CredentialFoundation\Dto\CredentialAuthenticationResult;
use Base3\CredentialFoundation\Dto\CredentialIdentityResult;
use Base3\CredentialFoundation\Dto\HmacAuthenticationRequest;
use Base3\KeyHarbor\Accesscontrol\KeyHarborAuthentication;
use PHPUnit\Framework\TestCase;

final class AuthenticationTestRequest implements IRequest {

	/** @param array<string,mixed> $server */
	public function __construct(private readonly array $server = []) {}

	public function get(string $key, $default = null) { return $default; }
	public function post(string $key, $default = null) { return $default; }
	public function request(string $key, $default = null) { return $default; }
	public function allRequest(): array { return []; }
	public function cookie(string $key, $default = null) { return $default; }
	public function session(string $key, $default = null) { return $default; }
	public function server(string $key, $default = null) { return $this->server[$key] ?? $default; }
	public function files(string $key, $default = null) { return $default; }
	public function allGet(): array { return []; }
	public function allPost(): array { return []; }
	public function allCookie(): array { return []; }
	public function allSession(): array { return []; }
	public function allServer(): array { return $this->server; }
	public function allFiles(): array { return []; }
	public function getJsonBody(): array { return []; }
	public function isCli(): bool { return false; }
	public function getContext(): string { return IRequest::CONTEXT_WEB_API; }
}
