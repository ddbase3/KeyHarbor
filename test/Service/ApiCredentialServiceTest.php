<?php declare(strict_types=1);

namespace KeyHarbor\Test\Service;

use Base3\Api\IClassMap;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\State\Api\IStateStore;
use CredentialFoundation\Api\ICredentialServiceProvider;
use CredentialFoundation\Dto\CredentialAuthenticationResult;
use CredentialFoundation\Dto\CredentialServiceDefinition;
use CredentialFoundation\Dto\HmacAuthenticationRequest;
use KeyHarbor\Api\ICredentialRepository;
use KeyHarbor\Model\ApiCredential;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Security\HmacRequestSigner;
use KeyHarbor\Service\ApiCredentialService;
use KeyHarbor\Service\CredentialServiceCatalog;
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

	/**
	 * @param array<int,string> $serviceIds
	 * @return array{0:ApiCredentialService,1:string,2:ApiCredential}
	 */
	private function serviceWithCredential(
		array $serviceIds = ['example:read'],
		?int $expiresAt = null,
		?int $revokedAt = null,
		bool $hmacEnabled = false,
		bool $technical = false
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
			$technical ? new \CredentialFoundation\Dto\CredentialSubject('service', 'contourz') : null,
			$technical ? ['example:read' => ['getEntries']] : []
		);
		$repository = new AuthenticationTestRepository([$credential]);
		$catalog = new CredentialServiceCatalog(new AuthenticationTestClassMap([
			new AuthenticationTestProvider()
		]));

		$signer = new HmacRequestSigner();
		return [
			new ApiCredentialService(
				$repository,
				$tokenService,
				$catalog,
				$cipher,
				$signer,
				new AuthenticationTestStateStore(),
				$configuration
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
