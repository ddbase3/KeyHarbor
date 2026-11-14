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

final class AuthenticationTestCredentialAccess implements ICredentialAccess {

	public int $resets = 0;
	public int $identifications = 0;
	public int $serviceAuthorizations = 0;
	public ?string $bearerToken = null;
	public ?HmacAuthenticationRequest $hmacRequest = null;

	public function __construct(
		private readonly ?CredentialIdentityResult $result = null
	) {}

	public function reset(): void {
		$this->resets++;
	}

	public function identifyBearer(string $token): CredentialIdentityResult {
		$this->identifications++;
		$this->bearerToken = $token;

		return $this->result ?? CredentialIdentityResult::failure(
			CredentialIdentityResult::FAILURE_INVALID_CREDENTIAL
		);
	}

	public function identifyHmac(HmacAuthenticationRequest $request): CredentialIdentityResult {
		$this->identifications++;
		$this->hmacRequest = $request;

		return $this->result ?? CredentialIdentityResult::failure(
			CredentialIdentityResult::FAILURE_INVALID_CREDENTIAL
		);
	}

	public function authorizeService(string $serviceId): CredentialAuthenticationResult {
		$this->serviceAuthorizations++;
		return CredentialAuthenticationResult::failure(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_GRANTED,
			$serviceId
		);
	}

	public function getIdentity(): ?CredentialIdentityResult {
		return $this->result;
	}
}
