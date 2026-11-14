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

namespace Base3\KeyHarbor\Test\Service;

use Base3\CoreFoundation\Api\IClassMap;
use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\ConfigValue\Api\IConfigValueResolver;
use Base3\CoreFoundation\State\Api\IStateStore;
use Base3\CredentialFoundation\Api\ICredentialServiceProvider;
use Base3\CredentialFoundation\Dto\CredentialAuthenticationResult;
use Base3\CredentialFoundation\Dto\CredentialIdentityResult;
use Base3\CredentialFoundation\Dto\CredentialServiceDefinition;
use Base3\CredentialFoundation\Dto\HmacAuthenticationRequest;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\Model\ApiCredential;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
use Base3\KeyHarbor\Security\CredentialTokenService;
use Base3\CredentialFoundation\Security\HmacRequestSigner;
use Base3\KeyHarbor\Service\ApiCredentialService;
use Base3\KeyHarbor\Service\CredentialServiceCatalog;
use PHPUnit\Framework\TestCase;

final class ApiCredentialServiceTest extends TestCase {

	public function testAuthenticatesValidBearerCredential(): void {
		[$service, $token, $credential] = $this->serviceWithCredential();

		$result = $service->authenticateBearer($token, 'example:read');

		self::assertTrue($result->isAuthenticated());
		self::assertSame($credential->getId(), $result->getCredentialId());
		self::assertSame('42', $result->getUserId());
		self::assertSame('example:read', $result->getServiceId());
		self::assertSame($credential->getExpiresAt(), $result->getExpiresAt());
	}

	public function testRejectsMalformedAndInvalidCredentials(): void {
		[$service, $token] = $this->serviceWithCredential();

		$malformed = $service->authenticateBearer('invalid', 'example:read');
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_MALFORMED_CREDENTIAL,
			$malformed->getFailureCode()
		);

		$invalid = $service->authenticateBearer($this->changeTokenSecret($token), 'example:read');
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_INVALID_CREDENTIAL,
			$invalid->getFailureCode()
		);
	}

	public function testRejectsRevokedAndExpiredCredentials(): void {
		[$revokedService, $revokedToken] = $this->serviceWithCredential(
			['example:read'],
			time() + 3600,
			time() - 10
		);
		$revoked = $revokedService->authenticateBearer($revokedToken, 'example:read');
		self::assertSame(CredentialAuthenticationResult::FAILURE_REVOKED, $revoked->getFailureCode());

		[$expiredService, $expiredToken] = $this->serviceWithCredential(
			['example:read'],
			time() - 1
		);
		$expired = $expiredService->authenticateBearer($expiredToken, 'example:read');
		self::assertSame(CredentialAuthenticationResult::FAILURE_EXPIRED, $expired->getFailureCode());
	}

	public function testRejectsUnknownAndUngrantedServices(): void {
		[$service, $token] = $this->serviceWithCredential(['example:write']);

		$unknown = $service->authenticateBearer($token, 'missing:service');
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_FOUND,
			$unknown->getFailureCode()
		);

		$notGranted = $service->authenticateBearer($token, 'example:read');
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_GRANTED,
			$notGranted->getFailureCode()
		);
	}

	public function testAuthenticatesHmacAndRejectsReplayAndBearerDowngrade(): void {
		[$service, $token, $credential, $signer] = $this->serviceWithCredential(
			['example:read'],
			time() + 3600,
			null,
			true
		);
		$timestamp = time();
		$unsigned = new HmacAuthenticationRequest(
			$token,
			'POST',
			'/example',
			'a=1',
			$timestamp,
			'nonce-1',
			str_repeat('a', 64),
			'{"ok":true}'
		);
		$secret = (new CredentialTokenService())->parse($token)?->getSecret();
		self::assertNotNull($secret);
		$request = new HmacAuthenticationRequest(
			$token,
			'POST',
			'/example',
			'a=1',
			$timestamp,
			'nonce-1',
			$signer->sign($unsigned, $secret),
			'{"ok":true}'
		);

		self::assertTrue($service->authenticateHmac($request, 'example:read')->isAuthenticated());
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_REPLAY_DETECTED,
			$service->authenticateHmac($request, 'example:read')->getFailureCode()
		);
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_HMAC_REQUIRED,
			$service->authenticateBearer($token, 'example:read')->getFailureCode()
		);
		self::assertTrue($credential->isHmacEnabled());
	}

	public function testServiceCredentialRequiresExplicitMethodAndNeverReturnsUserId(): void {
		[$service, $token, $credential, $signer] = $this->serviceWithCredential(['example:read'], null, null, true, true);
		$unsigned = new HmacAuthenticationRequest($token, 'GET', '/resource', '', time(), 'technical-nonce', str_repeat('a', 64));
		$secret = (new CredentialTokenService())->parse($token)->getSecret();
		$request = new HmacAuthenticationRequest($token, 'GET', '/resource', '', $unsigned->getTimestamp(), 'technical-nonce', $signer->sign($unsigned, $secret));
		$identity = $service->identifyHmac($request);
		self::assertTrue($identity->isAuthenticated());
		self::assertNull($identity->getUserId());
		self::assertSame('contourz', $identity->getSubject()->getId());
		self::assertSame(CredentialAuthenticationResult::FAILURE_OPERATION_REQUIRED, $service->authorizeService('example:read')->getFailureCode());
		self::assertSame(CredentialAuthenticationResult::FAILURE_OPERATION_NOT_GRANTED, $service->authorizeOperation('example:read', 'deleteEntry')->getFailureCode());
		self::assertTrue($service->authorizeOperation('example:read', 'getEntries')->isAuthenticated());
		self::assertNull($service->authorizeOperation('example:read', 'getEntries')->getUserId());
		self::assertSame(CredentialAuthenticationResult::FAILURE_HMAC_REQUIRED, $service->authenticateBearer($token, 'example:read')->getFailureCode());
	}

	public function testRejectsHmacForBearerOnlyCredential(): void {
		[$service, $token] = $this->serviceWithCredential();
		$request = new HmacAuthenticationRequest(
			$token,
			'POST',
			'/example',
			'',
			time(),
			'nonce',
			str_repeat('a', 64),
			'{}'
		);

		$result = $service->authenticateHmac($request, 'example:read');

		self::assertSame(
			CredentialAuthenticationResult::FAILURE_HMAC_NOT_ENABLED,
			$result->getFailureCode()
		);
	}

	public function testIdentifyBearerResetsPreviouslyAuthenticatedIdentityOnFailure(): void {
		[$service, $token] = $this->serviceWithCredential();
		self::assertTrue($service->identifyBearer($token)->isAuthenticated());
		self::assertTrue($service->getIdentity()?->isAuthenticated());

		$failure = $service->identifyBearer('malformed');
		self::assertFalse($failure->isAuthenticated());
		self::assertSame($failure, $service->getIdentity());
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_INVALID_CREDENTIAL,
			$service->authorizeService('example:read')->getFailureCode()
		);
	}

	public function testResetDiscardsIdentityAndPreventsFurtherServiceAccess(): void {
		[$service, $token] = $this->serviceWithCredential();
		self::assertTrue($service->identifyBearer($token)->isAuthenticated());

		$service->reset();
		self::assertNull($service->getIdentity());
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_INVALID_CREDENTIAL,
			$service->authorizeService('example:read')->getFailureCode()
		);
	}

	public function testRejectsOperationAuthorizationWithoutAuthenticatedIdentity(): void {
		[$service] = $this->serviceWithCredential();

		self::assertSame(
			CredentialAuthenticationResult::FAILURE_INVALID_CREDENTIAL,
			$service->authorizeOperation('example:read', 'getEntry')->getFailureCode()
		);
	}

	public function testUnknownOperationRemainsDeniedForAuthenticatedBearer(): void {
		[$service, $token] = $this->serviceWithCredential();
		self::assertTrue($service->identifyBearer($token)->isAuthenticated());

		self::assertSame(
			CredentialAuthenticationResult::FAILURE_OPERATION_NOT_FOUND,
			$service->authorizeOperation('example:read', 'undeclared')->getFailureCode()
		);
	}

	public function testHmacRejectsTimestampOutsideMaximumClockSkew(): void {
		[$service, $token] = $this->serviceWithCredential(['example:read'], null, null, true);
		$request = new HmacAuthenticationRequest(
			$token, 'POST', '/resource', '', time() - 7200,
			'valid-nonce', str_repeat('a', 64)
		);

		self::assertSame(
			CredentialIdentityResult::FAILURE_INVALID_TIMESTAMP,
			$service->authenticateHmac($request, 'example:read')->getFailureCode()
		);
	}

	public function testHmacRejectsOversizedNonceBeforeSignatureVerification(): void {
		[$service, $token] = $this->serviceWithCredential(['example:read'], null, null, true);
		$request = new HmacAuthenticationRequest(
			$token, 'POST', '/resource', '', time(),
			str_repeat('n', 256), str_repeat('a', 64)
		);

		self::assertSame(
			CredentialIdentityResult::FAILURE_INVALID_NONCE,
			$service->authenticateHmac($request, 'example:read')->getFailureCode()
		);
	}

	public function testHmacRejectsIncorrectRequestSignature(): void {
		[$service, $token] = $this->serviceWithCredential(['example:read'], null, null, true);
		$request = new HmacAuthenticationRequest(
			$token, 'POST', '/resource', '', time(),
			'valid-nonce', str_repeat('a', 64), 'body'
		);

		self::assertSame(
			CredentialIdentityResult::FAILURE_INVALID_SIGNATURE,
			$service->authenticateHmac($request, 'example:read')->getFailureCode()
		);
	}

	public function testSuccessfulIdentificationStillRequiresNonemptyServiceId(): void {
		[$service, $token] = $this->serviceWithCredential();
		self::assertTrue($service->identifyBearer($token)->isAuthenticated());

		self::assertSame(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_FOUND,
			$service->authorizeService('  ')->getFailureCode()
		);
	}

	public function testValidHmacTimestampWithinDefaultToleranceIsAccepted(): void {
		[$service, $token, , $signer] = $this->serviceWithCredential(['example:read'], null, null, true);
		$request = $this->signedHmacRequest($token, $signer, time() - 240, 'clock-window');

		self::assertTrue($service->identifyHmac($request)->isAuthenticated());
		self::assertTrue($service->authorizeService('example:read')->isAuthenticated());
	}

	public function testFutureHmacTimestampOutsideDefaultToleranceIsRejected(): void {
		[$service, $token] = $this->serviceWithCredential(['example:read'], null, null, true);
		$request = new HmacAuthenticationRequest(
			$token, 'GET', '/resource', '', time() + 3600, 'future-nonce', str_repeat('a', 64)
		);

		self::assertSame(
			CredentialIdentityResult::FAILURE_INVALID_TIMESTAMP,
			$service->identifyHmac($request)->getFailureCode()
		);
		self::assertFalse($service->getIdentity()->isAuthenticated());
	}

	public function testFreshSignedNoncesAreAcceptedButExactReplayIsRejected(): void {
		[$service, $token, , $signer] = $this->serviceWithCredential(['example:read'], null, null, true);
		$timestamp = time();
		$first = $this->signedHmacRequest($token, $signer, $timestamp, 'nonce-one');
		$second = $this->signedHmacRequest($token, $signer, $timestamp, 'nonce-two');

		self::assertTrue($service->identifyHmac($first)->isAuthenticated());
		self::assertSame(
			CredentialIdentityResult::FAILURE_REPLAY_DETECTED,
			$service->identifyHmac($first)->getFailureCode()
		);
		self::assertTrue($service->identifyHmac($second)->isAuthenticated());
	}

	public function testInvalidHmacSignatureInvalidatesPreviouslyAuthenticatedIdentity(): void {
		[$service, $token, , $signer] = $this->serviceWithCredential(['example:read'], null, null, true);
		$timestamp = time();
		$valid = $this->signedHmacRequest($token, $signer, $timestamp, 'valid-nonce');
		$invalid = new HmacAuthenticationRequest(
			$token, 'GET', '/resource', '', $timestamp, 'another-nonce', str_repeat('f', 64)
		);

		self::assertTrue($service->identifyHmac($valid)->isAuthenticated());
		self::assertSame(
			CredentialIdentityResult::FAILURE_INVALID_SIGNATURE,
			$service->identifyHmac($invalid)->getFailureCode()
		);
		self::assertFalse($service->getIdentity()->isAuthenticated());
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_INVALID_CREDENTIAL,
			$service->authorizeOperation('example:read', 'getEntries')->getFailureCode()
		);
	}

	public function testHmacIdentityCannotExpandAnAuthenticatedCredentialServiceGrant(): void {
		[$service, $token, , $signer] = $this->serviceWithCredential(['example:read'], null, null, true);
		$request = $this->signedHmacRequest($token, $signer, time(), 'grant-boundary');

		self::assertSame(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_GRANTED,
			$service->authenticateHmac($request, 'example:write')->getFailureCode()
		);
		self::assertTrue($service->getIdentity()->isAuthenticated());
		self::assertTrue($service->authorizeOperation('example:read', 'getEntries')->isAuthenticated());
	}

	public function testMissingIdentityNeverOverridesUnknownServiceValidation(): void {
		[$service] = $this->serviceWithCredential();

		self::assertNull($service->getIdentity());
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_FOUND,
			$service->authorizeService('missing:service')->getFailureCode()
		);
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_SERVICE_NOT_FOUND,
			$service->authorizeOperation('', 'getEntries')->getFailureCode()
		);
		self::assertNull($service->getIdentity());
	}

	public function testClockSkewConfigurationHasAThirtySecondMinimum(): void {
		[$service, $token, , $signer] = $this->serviceWithCredential(['example:read'], null, null, true, false, 0);
		self::assertTrue($service->identifyHmac(
			$this->signedHmacRequest($token, $signer, time() - 15, 'within-minimum')
		)->isAuthenticated());
		self::assertSame(
			CredentialIdentityResult::FAILURE_INVALID_TIMESTAMP,
			$service->identifyHmac(
				$this->signedHmacRequest($token, $signer, time() - 45, 'outside-minimum')
			)->getFailureCode()
		);
	}

	public function testClockSkewConfigurationHasAOneHourMaximum(): void {
		[$service, $token, , $signer] = $this->serviceWithCredential(['example:read'], null, null, true, false, 7200);
		self::assertTrue($service->identifyHmac(
			$this->signedHmacRequest($token, $signer, time() - 3450, 'within-maximum')
		)->isAuthenticated());
		self::assertSame(
			CredentialIdentityResult::FAILURE_INVALID_TIMESTAMP,
			$service->identifyHmac(
				$this->signedHmacRequest($token, $signer, time() - 3700, 'outside-maximum')
			)->getFailureCode()
		);
	}

	public function testWrongDeploymentMasterKeyRejectsHmacIdentityWithoutReturningTheSecret(): void {
		[$service, $token, , $signer] = $this->serviceWithCredential(
			['example:read'], null, null, true, false, null, true
		);
		$request = $this->signedHmacRequest($token, $signer, time(), 'unreadable-secret');
		$result = $service->identifyHmac($request);
		self::assertFalse($result->isAuthenticated());
		self::assertSame(CredentialIdentityResult::FAILURE_INVALID_CREDENTIAL, $result->getFailureCode());
		self::assertSame($result, $service->getIdentity());
		self::assertSame(
			CredentialAuthenticationResult::FAILURE_INVALID_CREDENTIAL,
			$service->authorizeService('example:read')->getFailureCode()
		);
	}

	public function testInvalidHmacSignatureDoesNotReserveNonceForAValidRequest(): void {
		[$service, $token, , $signer] = $this->serviceWithCredential(['example:read'], null, null, true);
		$timestamp = time();
		$nonce = 'retry-after-invalid-signature';
		$invalid = new HmacAuthenticationRequest(
			$token, 'GET', '/resource', '', $timestamp, $nonce, str_repeat('f', 64)
		);
		self::assertSame(
			CredentialIdentityResult::FAILURE_INVALID_SIGNATURE,
			$service->identifyHmac($invalid)->getFailureCode()
		);
		$valid = $this->signedHmacRequest($token, $signer, $timestamp, $nonce);
		self::assertTrue($service->identifyHmac($valid)->isAuthenticated());
		self::assertSame(
			CredentialIdentityResult::FAILURE_REPLAY_DETECTED,
			$service->identifyHmac($valid)->getFailureCode()
		);
	}

	private function signedHmacRequest(string $token, HmacRequestSigner $signer, int $timestamp, string $nonce): HmacAuthenticationRequest {
		$unsigned = new HmacAuthenticationRequest(
			$token, 'GET', '/resource', '', $timestamp, $nonce, str_repeat('0', 64)
		);
		$secret = (new CredentialTokenService())->parse($token)->getSecret();
		return new HmacAuthenticationRequest(
			$token, 'GET', '/resource', '', $timestamp, $nonce, $signer->sign($unsigned, $secret)
		);
	}

	/**
	 * @param array<int,string> $serviceIds
	 * @return array{0:ApiCredentialService,1:string,2:ApiCredential}
	 */
	private function serviceWithCredential(
		array $serviceIds = ['example:read'],
		?int $expiresAt = null,
		?int $revokedAt = null,
		bool $hmacEnabled = false,
		bool $technical = false,
		?int $clockSkewSeconds = null,
		bool $wrongDecryptionKey = false
	): array {
		$tokenService = new CredentialTokenService();
		$generated = $tokenService->generate();
		$configuration = new AuthenticationTestConfiguration();
		$cipher = new CredentialSecretCipher($configuration, new AuthenticationTestConfigValueResolver());
		$encrypted = $hmacEnabled ? $cipher->encrypt($generated->getSecret()) : null;
		$credential = new ApiCredential(
			$generated->getCredentialId(),
			$generated->getPublicId(),
			'42',
			'demo',
			'Demo User',
			'demo@example.test',
			'en',
			'Demo key',
			$generated->getSecretHash(),
			$hmacEnabled,
			$encrypted?->getCiphertext(),
			$encrypted?->getNonce(),
			time() - 60,
			$expiresAt,
			$revokedAt,
			null,
			null,
			$serviceIds,
			$technical ? new \Base3\CredentialFoundation\Dto\CredentialSubject('service', 'contourz') : null,
			$technical ? ['example:read' => ['getEntries']] : []
		);
		$repository = new AuthenticationTestRepository([$credential]);
		$catalog = new CredentialServiceCatalog(new AuthenticationTestClassMap([
			new AuthenticationTestProvider()
		]));

		$signer = new HmacRequestSigner();
		$serviceConfiguration = $configuration;
		if ($clockSkewSeconds !== null) {
			$serviceConfiguration = $this->createStub(IConfiguration::class);
			$serviceConfiguration->method('getInt')->willReturn($clockSkewSeconds);
		}
		if ($wrongDecryptionKey) {
			$keyConfiguration = $this->createStub(IConfiguration::class);
			$keyConfiguration->method('getValue')->willReturn(base64_encode(str_repeat('z', 32)));
			$cipher = new CredentialSecretCipher($keyConfiguration, new AuthenticationTestConfigValueResolver());
		}
		return [
			new ApiCredentialService(
				$repository,
				$tokenService,
				$catalog,
				$cipher,
				$signer,
				new AuthenticationTestStateStore(),
				$serviceConfiguration
			),
			$generated->getToken(),
			$credential,
			$signer
		];
	}

	private function changeTokenSecret(string $token): string {
		$last = substr($token, -1);
		return substr($token, 0, -1) . ($last === 'A' ? 'B' : 'A');
	}
}

require_once __DIR__ . '/AuthenticationTestProvider.php';

require_once __DIR__ . '/AuthenticationTestRepository.php';

require_once __DIR__ . '/AuthenticationTestClassMap.php';

require_once __DIR__ . '/AuthenticationTestStateStore.php';

require_once __DIR__ . '/AuthenticationTestConfiguration.php';

require_once __DIR__ . '/AuthenticationTestConfigValueResolver.php';
