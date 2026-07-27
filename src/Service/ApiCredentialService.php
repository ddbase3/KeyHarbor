<?php declare(strict_types=1);

namespace KeyHarbor\Service;

use Base3\Configuration\Api\IConfiguration;
use Base3\State\Api\IStateStore;
use CredentialFoundation\Api\IApiCredentialService;
use CredentialFoundation\Api\ICredentialAccess;
use CredentialFoundation\Dto\CredentialAuthenticationResult;
use CredentialFoundation\Dto\CredentialIdentityResult;
use CredentialFoundation\Dto\HmacAuthenticationRequest;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Exception\CredentialHmacConfigurationException;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Security\HmacRequestSigner;

/**
 * Authenticates credentials and authorizes their service grants.
 */
final class ApiCredentialService implements IApiCredentialService, ICredentialAccess {

	private const DEFAULT_CLOCK_SKEW_SECONDS = 300;
	private const MINIMUM_CLOCK_SKEW_SECONDS = 30;
	private const MAXIMUM_CLOCK_SKEW_SECONDS = 3600;
	private const NONCE_MAXIMUM_BYTES = 255;

	private ?ApiCredential $currentCredential = null;
	private ?CredentialIdentityResult $currentIdentity = null;

	public function __construct(
		private readonly ICredentialRepository $repository,
		private readonly CredentialTokenService $tokenService,
		private readonly CredentialServiceCatalog $serviceCatalog,
		private readonly CredentialSecretCipher $secretCipher,
		private readonly HmacRequestSigner $hmacRequestSigner,
		private readonly IStateStore $stateStore,
		private readonly IConfiguration $configuration
	) {}

	// Implementation of IApiCredentialService

	public function authenticateBearer(
		string $token,
		string $serviceId
	): CredentialAuthenticationResult {
		$identity = $this->identifyBearer($token);
		if (!$identity->isAuthenticated()) {
			return CredentialAuthenticationResult::failure(
				$identity->getFailureCode(),
				$serviceId
			);
		}

		return $this->authorizeService($serviceId);
	}

	public function authenticateHmac(
		HmacAuthenticationRequest $request,
		string $serviceId
	): CredentialAuthenticationResult {
		$identity = $this->identifyHmac($request);
		if (!$identity->isAuthenticated()) {
			return CredentialAuthenticationResult::failure(
				$identity->getFailureCode(),
				$serviceId
			);
		}

		return $this->authorizeService($serviceId);
	}

	// Implementation of ICredentialAccess

	public function reset(): void {
		$this->currentCredential = null;
		$this->currentIdentity = null;
	}

	public function identifyBearer(string $token): CredentialIdentityResult {
		$this->reset();

		$credential = $this->resolveCredential($token);
		if ($credential instanceof CredentialIdentityResult) {
			return $this->setIdentity($credential);
		}
		if ($credential->isHmacEnabled()) {
			return $this->setIdentity(CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_HMAC_REQUIRED
			));
		}

		return $this->setAuthenticatedCredential($credential);
	}

	public function identifyHmac(HmacAuthenticationRequest $request): CredentialIdentityResult {
		$this->reset();

		$credential = $this->resolveCredential($request->getToken());
		if ($credential instanceof CredentialIdentityResult) {
			return $this->setIdentity($credential);
		}
		if (!$credential->isHmacEnabled()) {
			return $this->setIdentity(CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_HMAC_NOT_ENABLED
			));
		}

		$clockSkew = $this->getClockSkewSeconds();
		if (abs(time() - $request->getTimestamp()) > $clockSkew) {
			return $this->setIdentity(CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_INVALID_TIMESTAMP
			));
		}
		if (!$this->isValidNonce($request->getNonce())) {
			return $this->setIdentity(CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_INVALID_NONCE
			));
		}

		try {
			$secret = $this->secretCipher->decrypt(
				(string)$credential->getSecretCiphertext(),
				(string)$credential->getSecretCipherNonce()
			);
		} catch (CredentialHmacConfigurationException) {
			return $this->setIdentity(CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_INVALID_CREDENTIAL
			));
		}

		try {
			$signatureValid = $this->hmacRequestSigner->verify($request, $secret);
		} finally {
			if (function_exists('sodium_memzero')) {
				sodium_memzero($secret);
			}
		}

		if (!$signatureValid) {
			return $this->setIdentity(CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_INVALID_SIGNATURE
			));
		}

		$nonceKey = 'keyharbor.hmac.nonce.' . $credential->getId() . '.' . hash('sha256', $request->getNonce());
		if (!$this->stateStore->setIfNotExists(
			$nonceKey,
			$request->getTimestamp(),
			($clockSkew * 2) + 60
		)) {
			return $this->setIdentity(CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_REPLAY_DETECTED
			));
		}

		return $this->setAuthenticatedCredential($credential);
	}

	public function authorizeService(string $serviceId): CredentialAuthenticationResult {
		$serviceId = trim($serviceId);
		if ($serviceId === '') {
			return CredentialAuthenticationResult::failure(
				CredentialAuthenticationResult::FAILURE_SERVICE_NOT_FOUND,
				$serviceId
			);
		}
		if ($this->currentCredential === null || $this->currentIdentity === null || !$this->currentIdentity->isAuthenticated()) {
			return CredentialAuthenticationResult::failure(
				CredentialAuthenticationResult::FAILURE_INVALID_CREDENTIAL,
				$serviceId
			);
		}
		if (!$this->serviceCatalog->hasService($serviceId)) {
			return CredentialAuthenticationResult::failure(
				CredentialAuthenticationResult::FAILURE_SERVICE_NOT_FOUND,
				$serviceId
			);
		}
		if (!$this->currentCredential->hasService($serviceId)) {
			return CredentialAuthenticationResult::failure(
				CredentialAuthenticationResult::FAILURE_SERVICE_NOT_GRANTED,
				$serviceId
			);
		}

		return CredentialAuthenticationResult::success(
			$this->currentCredential->getId(),
			$this->currentCredential->getOwnerUserId(),
			$serviceId,
			$this->currentCredential->getExpiresAt()
		);
	}

	public function getIdentity(): ?CredentialIdentityResult {
		return $this->currentIdentity;
	}

	private function resolveCredential(string $token): ApiCredential|CredentialIdentityResult {
		$parsedToken = $this->tokenService->parse($token);
		if ($parsedToken === null) {
			return CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_MALFORMED_CREDENTIAL
			);
		}

		$credential = $this->repository->getByPublicId($parsedToken->getPublicId());
		if ($credential === null || !$this->tokenService->verifySecret(
			$parsedToken->getSecret(),
			$credential->getSecretHash()
		)) {
			return CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_INVALID_CREDENTIAL
			);
		}
		if ($credential->isRevoked()) {
			return CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_REVOKED
			);
		}
		if ($credential->isExpired(time())) {
			return CredentialIdentityResult::failure(
				CredentialIdentityResult::FAILURE_EXPIRED
			);
		}

		return $credential;
	}

	private function setAuthenticatedCredential(ApiCredential $credential): CredentialIdentityResult {
		$this->currentCredential = $credential;

		return $this->setIdentity(CredentialIdentityResult::success(
			$credential->getId(),
			$credential->getOwnerUserId(),
			$credential->getExpiresAt()
		));
	}

	private function setIdentity(CredentialIdentityResult $identity): CredentialIdentityResult {
		$this->currentIdentity = $identity;
		return $identity;
	}

	private function getClockSkewSeconds(): int {
		$seconds = $this->configuration->getInt(
			'keyharbor',
			'hmac_clock_skew_seconds',
			self::DEFAULT_CLOCK_SKEW_SECONDS
		);

		return max(
			self::MINIMUM_CLOCK_SKEW_SECONDS,
			min(self::MAXIMUM_CLOCK_SKEW_SECONDS, $seconds)
		);
	}

	private function isValidNonce(string $nonce): bool {
		$nonce = trim($nonce);
		return $nonce !== '' &&
			strlen($nonce) <= self::NONCE_MAXIMUM_BYTES &&
			!preg_match('/[\x00-\x1F\x7F]/', $nonce);
	}
}
