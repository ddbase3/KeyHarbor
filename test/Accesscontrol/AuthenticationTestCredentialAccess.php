<?php declare(strict_types=1);

namespace KeyHarbor\Test\Accesscontrol;

use Base3\Api\IRequest;
use CredentialFoundation\Api\ICredentialAccess;
use CredentialFoundation\Dto\CredentialAuthenticationResult;
use CredentialFoundation\Dto\CredentialIdentityResult;
use CredentialFoundation\Dto\HmacAuthenticationRequest;
use KeyHarbor\Accesscontrol\KeyHarborAuthentication;
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
