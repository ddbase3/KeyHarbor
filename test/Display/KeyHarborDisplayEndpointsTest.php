<?php declare(strict_types=1);

namespace KeyHarbor\Test\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IMvcView;
use Base3\Api\IRequest;
use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationDecision;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Configuration\Api\IConfiguration;
use Base3\ConfigValue\Api\IConfigValueResolver;
use Base3\Crypt\Api\ICrypt;
use Base3\LinkTarget\Api\ILinkTargetService;
use Base3\Logger\Api\ILogger;
use Base3\Usermanager\User;
use CredentialFoundation\Api\ICredentialServiceProvider;
use CredentialFoundation\Api\IEncryptionTargetProvider;
use KeyHarbor\Display\EncryptionProfilesDisplay;
use KeyHarbor\Display\KeyHarborAdminDisplay;
use KeyHarbor\Display\KeyHarborServiceAdminDisplay;
use KeyHarbor\Display\KeyManagementDisplay;
use KeyHarbor\Security\CredentialSecretCipher;
use KeyHarbor\Security\CredentialTokenService;
use KeyHarbor\Service\CredentialManagementService;
use KeyHarbor\Service\CredentialServiceCatalog;
use KeyHarbor\Service\EncryptionProfileService;
use KeyHarbor\SettingsTab\EncryptionprofilesSettingsTab;
use KeyHarbor\SettingsTab\KeyharboradminSettingsTab;
use KeyHarbor\SettingsTab\KeyharborserviceadminSettingsTab;
use KeyHarbor\SettingsTab\KeymanagementSettingsTab;
use KeyHarbor\Test\Service\EncryptionExampleTargetProvider;
use KeyHarbor\Test\Service\EncryptionTestClassMap;
use KeyHarbor\Test\Service\EncryptionTestSettingsStore;
use KeyHarbor\Test\Service\ManagementTestProvider;
use KeyHarbor\Test\Service\ManagementTestRepository;
use KeyHarbor\Test\Service\ManagementTestUsermanager;
use PHPUnit\Framework\TestCase;

final class KeyHarborDisplayEndpointsTest extends TestCase {

	public function testUserJsonListContainsOnlyOwnedCredentialsAndNotSecrets(): void {
		[$display, $repository, $management] = $this->context('user', ['mode' => 'list']);
		$management->createForCurrentUser('Visible', '', '', null, ['example:read']);
		$management->createForService('not-available', 'Service', '', 'en', null, ['example:read' => ['getEntries']]);
		$body = $this->json($display);
		self::assertTrue($body['ok']);
		self::assertCount(1, $body['credentials']);
		self::assertSame('Visible', $body['credentials'][0]['label']);
		self::assertSame('42', $body['profile']['id']);
		self::assertSame('demo', $body['profile']['login']);
		self::assertTrue($body['profile']['admin']);
		self::assertArrayNotHasKey('secret_hash', $body['credentials'][0]);
		self::assertArrayNotHasKey('token', $body['credentials'][0]);
		self::assertCount(2, $body['services']);
	}

	public function testUserCreateReturnsTokenOnlyInOneTimeResponse(): void {
		[$display, $repository] = $this->context('user', [
			'mode' => 'create', 'label' => 'Test access', 'service_ids' => ['example:read']
		]);
		$body = $this->json($display);
		self::assertTrue($body['ok']);
		self::assertSame('created', $body['action']);
		self::assertStringStartsWith('b3k_', $body['token']);
		self::assertSame('Test access', $body['credential']['label']);
		self::assertSame('active', $body['credential']['status']);
		self::assertArrayNotHasKey('token', $body['credential']);
		self::assertArrayNotHasKey('secret_hash', $body['credential']);
		self::assertCount(1, $repository->listAll());
	}

	public function testUserRequestCanUpdateAnOwnedCredential(): void {
		[$display, $repository, $management] = $this->context('user', [
			'mode' => 'update', 'label' => 'Changed', 'notification_language' => 'de',
			'notification_address' => 'notify@example.test', 'service_ids' => ['example:write']
		]);
		$credential = $management->createForCurrentUser('Original', '', '', null, ['example:read'])->getCredential();
		[$display] = $this->context('user', [
			'mode' => 'update', 'credential_id' => $credential->getId(),
			'label' => 'Changed', 'notification_language' => 'de',
			'notification_address' => 'notify@example.test', 'service_ids' => ['example:write']
		], repository: $repository);
		$body = $this->json($display);
		self::assertTrue($body['ok']);
		self::assertSame('updated', $body['action']);
		self::assertSame('Changed', $body['credential']['label']);
		self::assertSame(['example:write'], $body['credential']['service_ids']);
		self::assertArrayNotHasKey('token', $body);
	}

	public function testUserRequestCanRotateRevokeAndDeleteCredential(): void {
		[$display, $repository, $management] = $this->context('user', []);
		$issued = $management->createForCurrentUser('Rotatable', '', '', null, ['example:read']);
		$id = $issued->getCredential()->getId();
		[$rotation] = $this->context('user', ['mode' => 'rotate', 'credential_id' => $id], repository: $repository);
		$rotated = $this->json($rotation);
		self::assertTrue($rotated['ok']);
		self::assertSame('rotated', $rotated['action']);
		self::assertNotSame($issued->getGeneratedToken()->getToken(), $rotated['token']);
		[$revocation] = $this->context('user', ['mode' => 'revoke', 'credential_id' => $id], repository: $repository);
		$revoked = $this->json($revocation);
		self::assertTrue($revoked['ok']);
		self::assertSame('revoked', $revoked['credential']['status']);
		[$deletion] = $this->context('user', ['mode' => 'delete', 'credential_id' => $id], repository: $repository);
		$deleted = $this->json($deletion);
		self::assertTrue($deleted['ok']);
		self::assertSame($id, $deleted['credential_id']);
		self::assertNull($repository->getById($id));
	}

	public function testUserRequestWithoutLoginReturnsAuthenticationError(): void {
		[$display] = $this->context('user', ['mode' => 'list'], authenticated: false);
		$body = $this->json($display);
		self::assertFalse($body['ok']);
		self::assertSame('not_authenticated', $body['error_code']);
	}

	public function testUserRequestRejectsInvalidServiceIdPayload(): void {
		[$display, $repository] = $this->context('user', [
			'mode' => 'create', 'label' => 'Invalid', 'service_ids' => 'example:read'
		]);
		self::assertSame('invalid_input', $this->json($display)['error_code']);
		self::assertSame([], $repository->listAll());
	}

	public function testUserRequestRejectsInvalidExpiryWithoutCreatingCredential(): void {
		foreach ([false, -1, '2000.5', 'later', []] as $expiresAt) {
			[$display, $repository] = $this->context('user', [
				'mode' => 'create', 'label' => 'Invalid', 'expires_at' => $expiresAt,
				'service_ids' => ['example:read']
			]);
			self::assertSame('invalid_input', $this->json($display)['error_code']);
			self::assertSame([], $repository->listAll());
		}
	}

	public function testUserRequestRejectsUnsupportedModes(): void {
		[$display] = $this->context('user', ['mode' => 'grant_admin']);
		self::assertSame('invalid_input', $this->json($display)['error_code']);
	}

	public function testAdminListShowsTechnicalCredentialsWithExplicitSubjects(): void {
		[$display, $repository, $management] = $this->context('admin', ['mode' => 'list']);
		$management->createForCurrentUser('User', '', '', null, ['example:read']);
		$management->createForService('contourz', 'Service', '', 'en', null, ['example:read' => ['getEntries']]);
		$body = $this->json($display);
		self::assertTrue($body['ok']);
		self::assertCount(2, $body['credentials']);
		self::assertSame(['user', 'service'], array_column($body['credentials'], 'subject_type'));
		self::assertSame('contourz', $body['credentials'][1]['subject_id']);
		self::assertSame(['example:read' => ['getEntries']], $body['credentials'][1]['operation_grants']);
		self::assertArrayNotHasKey('secret_hash', $body['credentials'][1]);
	}

	public function testServiceOnlyAdminListExcludesUserCredentials(): void {
		[$display, $repository, $management] = $this->context('service_admin', ['mode' => 'list']);
		$management->createForCurrentUser('User', '', '', null, ['example:read']);
		$management->createForService('contourz', 'Service', '', 'en', null, ['example:read' => ['getEntries']]);
		$body = $this->json($display);
		self::assertTrue($body['ok']);
		self::assertCount(1, $body['credentials']);
		self::assertSame('service', $body['credentials'][0]['subject_type']);
	}

	public function testAdminCanCreateTechnicalCredentialViaJson(): void {
		[$display, $repository] = $this->context('admin', [
			'mode' => 'create_service', 'subject_id' => 'contourz', 'label' => 'Integration',
			'notification_address' => 'notify@example.test', 'notification_language' => 'en',
			'operation_grants' => ['example:read' => ['getEntries']]
		]);
		$body = $this->json($display);
		self::assertTrue($body['ok']);
		self::assertSame('created', $body['action']);
		self::assertSame('service', $body['credential']['subject_type']);
		self::assertSame('contourz', $body['credential']['subject_id']);
		self::assertSame(['example:read' => ['getEntries']], $body['credential']['operation_grants']);
		self::assertStringStartsWith('b3k_', $body['token']);
		self::assertCount(1, $repository->listAll());
	}

	public function testAdminRequestRejectsOperationGrantsAsList(): void {
		foreach ([[['getEntries']], ['example:read' => 'getEntries'], ['example:read' => ['getEntries' => true]]] as $grants) {
			[$display, $repository] = $this->context('admin', [
				'mode' => 'create_service', 'subject_id' => 'contourz', 'label' => 'Invalid',
				'operation_grants' => $grants
			]);
			self::assertSame('invalid_input', $this->json($display)['error_code']);
			self::assertSame([], $repository->listAll());
		}
	}

	public function testAdminRequestRejectsResourceConstraintsAsList(): void {
		[$display, $repository] = $this->context('admin', [
			'mode' => 'create_service', 'subject_id' => 'contourz', 'label' => 'Invalid',
			'operation_grants' => ['example:read' => ['getEntries']],
			'resource_constraints' => [['name' => 'other']]
		]);
		self::assertSame('invalid_input', $this->json($display)['error_code']);
		self::assertSame([], $repository->listAll());
	}

	public function testAdminRequestRejectsMalformedExpiry(): void {
		[$display] = $this->context('admin', [
			'mode' => 'create_service', 'expires_at' => 'tomorrow',
			'operation_grants' => ['example:read' => ['getEntries']]
		]);
		self::assertSame('invalid_input', $this->json($display)['error_code']);
	}

	public function testAdminRequestRejectsUnknownMode(): void {
		[$display] = $this->context('admin', ['mode' => 'delete_all']);
		self::assertSame('invalid_input', $this->json($display)['error_code']);
	}

	public function testMissingAdministratorPermissionDeniesAdminJsonButNotUserAccess(): void {
		[$admin] = $this->context('admin', ['mode' => 'list'], adminAllowed: false);
		$body = $this->json($admin);
		self::assertFalse($body['ok']);
		self::assertSame('access_denied', $body['error_code']);
		[$user] = $this->context('user', ['mode' => 'list'], adminAllowed: false);
		self::assertTrue($this->json($user)['ok']);
	}

	public function testJsonEndpointsRejectNonAjaxRequests(): void {
		$invalid = [
			['REQUEST_METHOD' => 'GET'],
			['HTTP_X_REQUESTED_WITH' => 'not-ajax'],
			['CONTENT_TYPE' => 'text/plain']
		];
		foreach (['user', 'admin', 'encryption'] as $type) {
			foreach ($invalid as $headers) {
				[$display] = $this->context($type, ['mode' => 'list'], headers: $headers);
				$body = $this->json($display);
				self::assertFalse($body['ok']);
				self::assertSame($type === 'encryption' ? 'validation' : 'invalid_input', $body['error_code']);
			}
		}
	}

	public function testEncryptionListReturnsProfileMethodsTargetsAndAssignments(): void {
		[$display, $repository, $management, $encryption] = $this->context('encryption', ['mode' => 'list']);
		$encryption->saveProfileForAdmin([
			'id' => 'profile-a', 'label' => 'Example', 'method' => 'test-method',
			'key_id' => 'key-a', 'keys' => [['id' => 'key-a', 'mode' => 'env', 'name' => 'SECRET']]
		]);
		$encryption->assignForAdmin('example:store', 'profile-a');
		$body = $this->json($display);
		self::assertTrue($body['ok']);
		self::assertSame(['test-method'], $body['methods']);
		self::assertSame('profile-a', $body['profiles'][0]['id']);
		self::assertSame('example:store', $body['targets'][0]['target_id']);
		self::assertSame(['example:store' => 'profile-a'], $body['assignments']);
	}

	public function testEncryptionSaveAndRotateReturnOnlyPublicIdentifiers(): void {
		[$display, $repository, $management, $encryption, $store] = $this->context('encryption', [
			'mode' => 'save', 'profile' => [
				'id' => 'profile-a', 'label' => 'Fixed', 'method' => 'test-method',
				'key_id' => '', 'keys' => []
			]
		]);
		self::assertSame(['ok' => true], $this->json($display));
		$old = $store->get('keyharbor_encryption_profiles', 'profile-a');
		self::assertCount(1, $old['keys']);
		[$rotate] = $this->context('encryption', ['mode' => 'rotate', 'profile_id' => 'profile-a'], settings: $store);
		$body = $this->json($rotate);
		self::assertTrue($body['ok']);
		self::assertMatchesRegularExpression('/^key-[0-9a-f]{24}$/', $body['key_id']);
		self::assertArrayNotHasKey('secret', $body);
		self::assertArrayNotHasKey('ciphertext', $body);
		self::assertCount(2, $store->get('keyharbor_encryption_profiles', 'profile-a')['keys']);
	}

	public function testEncryptionAssignmentRequiresConfirmation(): void {
		[$display, $repository, $management, $encryption, $store] = $this->context('encryption', [
			'mode' => 'assign', 'target_id' => 'example:store', 'profile_id' => 'profile-b'
		]);
		foreach (['profile-a', 'profile-b'] as $id) {
			$encryption->saveProfileForAdmin([
				'id' => $id, 'label' => $id, 'method' => 'test-method',
				'key_id' => 'key-' . $id, 'keys' => [['id' => 'key-' . $id, 'mode' => 'env', 'name' => 'SECRET']]
			]);
		}
		$encryption->assignForAdmin('example:store', 'profile-a');
		$body = $this->json($display);
		self::assertSame('validation', $body['error_code']);
		self::assertSame('assignment_confirmation', $body['validation_reason']);
		self::assertSame('profile-a', $encryption->getAssignedProfileId('example:store'));
		[$confirmed] = $this->context('encryption', [
			'mode' => 'assign', 'target_id' => 'example:store', 'profile_id' => 'profile-b',
			'confirm_change' => true
		], settings: $store);
		self::assertSame(['ok' => true], $this->json($confirmed));
		self::assertSame('profile-b', $encryption->getAssignedProfileId('example:store'));
	}

	public function testEncryptionSaveRejectsMissingProfileAndUnsupportedMode(): void {
		foreach ([['mode' => 'save'], ['mode' => 'save', 'profile' => 'invalid'], ['mode' => 'delete']] as $body) {
			[$display] = $this->context('encryption', $body);
			self::assertSame('validation', $this->json($display)['error_code']);
		}
	}

	public function testEncryptionValidationReasonIsPreserved(): void {
		[$display] = $this->context('encryption', [
			'mode' => 'save', 'profile' => [
				'id' => 'INVALID', 'label' => 'Invalid', 'method' => 'test-method',
				'key_id' => 'key-a', 'keys' => [['id' => 'key-a', 'mode' => 'env', 'name' => 'SECRET']]
			]
		]);
		$body = $this->json($display);
		self::assertFalse($body['ok']);
		self::assertSame('validation', $body['error_code']);
		self::assertSame('invalid_identifier', $body['validation_reason']);
	}

	public function testEncryptionRequiresNativeSystemAdministrationPermission(): void {
		[$display] = $this->context('encryption', ['mode' => 'list'], adminAllowed: false);
		self::assertSame('access_denied', $this->json($display)['error_code']);
	}

	public function testEncryptionMissingMasterKeyReturnsConfigurationErrorWithoutDetails(): void {
		[$display, $repository, $management, $encryption, $store] = $this->context('encryption', [
			'mode' => 'save', 'profile' => [
				'id' => 'profile-a', 'label' => 'Fixed', 'method' => 'test-method',
				'key_id' => '', 'keys' => []
			]
		], masterKeyConfigured: false);
		$body = $this->json($display);
		self::assertFalse($body['ok']);
		self::assertSame('hmac_configuration', $body['error_code']);
		self::assertArrayNotHasKey('error', $body);
		self::assertSame([], $store->getGroup('keyharbor_encryption_profiles'));
	}

	public function testKeyManagementHelpUsesTranslatedValueOrSafeDefault(): void {
		[$display] = $this->context('user', [], translations: ['help' => '  Help in the current language  ']);
		self::assertSame('Help in the current language', $display->getHelp());
		[$fallback] = $this->context('user', []);
		self::assertSame('Manage API credentials owned by the current user.', $fallback->getHelp());
	}

	public function testJsonOutputIsCaseInsensitiveForAllThreeDisplays(): void {
		foreach (['user', 'admin', 'encryption'] as $type) {
			[$display] = $this->context($type, ['mode' => 'list']);
			self::assertTrue($this->json($display, 'JSON')['ok']);
		}
	}

	public function testAdminJsonUpdatesTechnicalCredentialWithoutExposingToken(): void {
		[$display, $repository, $management] = $this->context('admin', ['mode' => 'list']);
		$original = $management->createForService('contourz', 'Original', '', 'en', null, ['example:read' => ['getEntries']])->getCredential();
		[$update] = $this->context('admin', [
			'mode' => 'update_service', 'credential_id' => $original->getId(),
			'label' => 'Revised', 'notification_address' => 'notify@example.test',
			'notification_language' => 'de', 'operation_grants' => ['example:read' => ['getEntry']]
		], repository: $repository);
		$body = $this->json($update);
		self::assertTrue($body['ok']);
		self::assertSame('updated', $body['action']);
		self::assertSame('Revised', $body['credential']['label']);
		self::assertSame('contourz', $body['credential']['subject_id']);
		self::assertSame(['example:read' => ['getEntry']], $body['credential']['operation_grants']);
		self::assertArrayNotHasKey('token', $body);
	}

	public function testAdminJsonRotatesRevokesAndDeletesTechnicalCredential(): void {
		[$display, $repository, $management] = $this->context('admin', ['mode' => 'list']);
		$issued = $management->createForService('contourz', 'Service', '', 'en', null, ['example:read' => ['getEntries']]);
		$id = $issued->getCredential()->getId();
		[$rotate] = $this->context('service_admin', ['mode' => 'rotate_service', 'credential_id' => $id], repository: $repository);
		$rotated = $this->json($rotate);
		self::assertTrue($rotated['ok']);
		self::assertSame('rotated', $rotated['action']);
		self::assertNotSame($issued->getGeneratedToken()->getToken(), $rotated['token']);
		[$revoke] = $this->context('service_admin', ['mode' => 'revoke', 'credential_id' => $id], repository: $repository);
		$revoked = $this->json($revoke);
		self::assertTrue($revoked['ok']);
		self::assertSame('revoked', $revoked['credential']['status']);
		[$delete] = $this->context('service_admin', ['mode' => 'delete', 'credential_id' => $id], repository: $repository);
		self::assertSame('deleted', $this->json($delete)['action']);
		self::assertNull($repository->getById($id));
	}

	public function testAdminCannotDeleteUnrevokedCredentialFromJson(): void {
		[$display, $repository, $management] = $this->context('admin', ['mode' => 'list']);
		$credential = $management->createForService('contourz', 'Active', '', 'en', null, ['example:read' => ['getEntries']])->getCredential();
		[$delete] = $this->context('admin', ['mode' => 'delete', 'credential_id' => $credential->getId()], repository: $repository);
		$body = $this->json($delete);
		self::assertFalse($body['ok']);
		self::assertSame('not_revoked', $body['error_code']);
		self::assertNotNull($repository->getById($credential->getId()));
	}

	public function testUserJsonRejectsTechnicalCredentialModification(): void {
		[$display, $repository, $management] = $this->context('admin', ['mode' => 'list']);
		$credential = $management->createForService('contourz', 'Service', '', 'en', null, ['example:read' => ['getEntries']])->getCredential();
		[$update] = $this->context('user', [
			'mode' => 'update', 'credential_id' => $credential->getId(),
			'label' => 'Unauthorized', 'service_ids' => ['example:read']
		], repository: $repository);
		$body = $this->json($update);
		self::assertFalse($body['ok']);
		self::assertSame('not_found', $body['error_code']);
		self::assertSame('Service', $repository->getById($credential->getId())?->getLabel());
	}

	public function testServiceAdminHtmlReceivesDistinctDisplayMode(): void {
		[$display, , , , , $view] = $this->context('service_admin', []);
		$assigned = [];
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		self::assertSame('<section>test</section>', $display->getOutput('html'));
		self::assertTrue($assigned['serviceOnly']);
		self::assertSame('/api', $assigned['service']);
		self::assertSame('/plugin/KeyHarbor/assets/keyharbor/keyharboradmin.js', $assigned['scriptUrl']);
	}

	public function testUserHtmlRequiresLoginAndProvidesLocalizedError(): void {
		[$display, , , , , $view] = $this->context('user', [], authenticated: false, translations: [
			'error_not_authenticated' => 'Please sign in'
		]);
		$assigned = [];
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		self::assertSame('<section>test</section>', $display->getOutput('html'));
		self::assertSame('Please sign in', $assigned['error']);
		self::assertSame([], $assigned['profile']);
	}

	public function testAdminHtmlRequiresNativeAdministratorAndKeepsLocalizedError(): void {
		[$display, , , , , $view] = $this->context('admin', [], adminAllowed: false, translations: [
			'error_access_denied' => 'Administrator rights required'
		]);
		$assigned = [];
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		self::assertSame('<section>test</section>', $display->getOutput('html'));
		self::assertSame('Administrator rights required', $assigned['error']);
		self::assertFalse($assigned['serviceOnly']);
	}

	public function testEncryptionHtmlReportsDeniedSystemAdministrator(): void {
		[$display, , , , , $view] = $this->context('encryption', [], adminAllowed: false);
		$assigned = [];
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		self::assertSame('<section>test</section>', $display->getOutput('html'));
		self::assertSame('access_denied', $assigned['errorCode']);
		self::assertSame('/plugin/KeyHarbor/assets/keyharbor/encryptionprofiles.js', $assigned['scriptUrl']);
	}

	public function testAdminHelpUsesSeparateTranslationForServiceOnlyDisplay(): void {
		$bricks = ['help' => 'Admin overview', 'service_page_help' => 'Technical overview'];
		[$admin] = $this->context('admin', [], translations: $bricks);
		[$serviceAdmin] = $this->context('service_admin', [], translations: $bricks);
		self::assertSame('Admin overview', $admin->getHelp());
		self::assertSame('Technical overview', $serviceAdmin->getHelp());
	}

	public function testAdminMissingHelpTranslationRaisesExplicitError(): void {
		[$display] = $this->context('admin', []);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Missing KeyHarbor translation: help');
		$display->getHelp();
	}

	public function testEncryptionHelpUsesPluginTranslationOrEmptyValue(): void {
		[$display] = $this->context('encryption', [], translations: ['help' => '  Encryption help  ']);
		self::assertSame('  Encryption help  ', $display->getHelp());
		[$fallback] = $this->context('encryption', []);
		self::assertSame('', $fallback->getHelp());
	}

	public function testDisplaysExposeStableNamesAndTheirSetDataMethodsDoNotMutateRequests(): void {
		$names = [
			'user' => 'keymanagementdisplay',
			'admin' => 'keyharboradmindisplay',
			'service_admin' => 'keyharborserviceadmindisplay',
			'encryption' => 'encryptionprofilesdisplay'
		];
		foreach ($names as $type => $name) {
			[$display] = $this->context($type, ['mode' => 'list']);
			self::assertSame($name, $display::getName());
			self::assertNull($display->setData(['mode' => 'delete', 'credential_id' => 'not-request-data']));
			self::assertTrue($this->json($display)['ok']);
		}
	}

	public function testFeatureDenialStopsHtmlAndJsonBeforeCredentialAdministration(): void {
		foreach (['user', 'admin', 'service_admin', 'encryption'] as $type) {
			[$display] = $this->context($type, ['mode' => 'list'], featureAllowed: false);
			self::assertSame('', $display->getOutput('html'));
			self::assertSame(403, http_response_code());
			self::assertSame('', $display->getOutput('json'));
			self::assertSame(403, http_response_code());
		}
		http_response_code(200);
	}

	public function testFinalJsonOutputDoesNotRequireASeparateRequestPath(): void {
		foreach (['user', 'admin', 'service_admin', 'encryption'] as $type) {
			[$display] = $this->context($type, ['mode' => 'list']);
			$body = json_decode($display->getOutput('JSON', true), true, 512, JSON_THROW_ON_ERROR);
			self::assertTrue($body['ok']);
		}
	}

	public function testUnexpectedAuthorizationFailureProducesInternalErrorWithoutLeakingDetails(): void {
		foreach (['user', 'admin', 'service_admin', 'encryption'] as $type) {
			[$display] = $this->context($type, ['mode' => 'list'], throwOnAdminCheck: true);
			$body = $this->json($display);
			self::assertFalse($body['ok']);
			self::assertSame('internal_error', $body['error_code']);
			self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/D', $body['error_id']);
			self::assertStringNotContainsString('private authorization backend unavailable', json_encode($body, JSON_THROW_ON_ERROR));
		}
	}

	public function testUnexpectedAuthorizationFailureRendersLocalizedHtmlWithoutExposingDetails(): void {
		foreach (['user', 'admin', 'service_admin', 'encryption'] as $type) {
			[$display, , , , , $view] = $this->context(
				$type, [], throwOnAdminCheck: true,
				translations: ['unavailable_error' => 'Administration temporarily unavailable']
			);
			$assigned = [];
			$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
				$assigned[$key] = $value;
			});
			self::assertSame('<section>test</section>', $display->getOutput('html'));
			if ($type === 'encryption') {
				self::assertSame('unavailable', $assigned['errorCode']);
			} else {
				self::assertSame('Administration temporarily unavailable', $assigned['error']);
			}
			self::assertStringNotContainsString('private authorization backend unavailable', json_encode($assigned, JSON_THROW_ON_ERROR));
		}
	}

	public function testAdminHtmlMissingUnavailableTranslationFailsExplicitly(): void {
		[$display] = $this->context('admin', [], throwOnAdminCheck: true);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Missing KeyHarbor translation: unavailable_error');
		$display->getOutput('html');
	}

	public function testServiceAdminHelpRequiresDedicatedTranslation(): void {
		[$display] = $this->context('service_admin', [], translations: ['help' => 'General help']);
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Missing KeyHarbor translation: service_page_help');
		$display->getHelp();
	}

	public function testAdminUpdateRejectsMalformedGrantsWithoutChangingCredential(): void {
		[$display, $repository, $management] = $this->context('admin', ['mode' => 'list']);
		$credential = $management->createForService('integration', 'Original', '', 'en', null, [
			'example:read' => ['getEntries']
		])->getCredential();
		[$invalid] = $this->context('admin', [
			'mode' => 'update_service', 'credential_id' => $credential->getId(),
			'label' => 'Changed', 'operation_grants' => [['getEntries']]
		], repository: $repository);
		self::assertSame('invalid_input', $this->json($invalid)['error_code']);
		self::assertSame('Original', $repository->getById($credential->getId())?->getLabel());
	}

	public function testHmacUserCreationWithoutMasterKeyReturnsHmacConfigurationError(): void {
		[$display, $repository] = $this->context('user', [
			'mode' => 'create', 'label' => 'HMAC credential',
			'authentication_mode' => 'hmac', 'service_ids' => ['example:read']
		], masterKeyConfigured: false);
		$body = $this->json($display);
		self::assertFalse($body['ok']);
		self::assertSame('hmac_configuration', $body['error_code']);
		self::assertSame([], $repository->listAll());
	}

	public function testAdminCreationWithoutMasterKeyFailsClosedAndDoesNotPersist(): void {
		[$display, $repository] = $this->context('admin', [
			'mode' => 'create_service', 'subject_id' => 'integration', 'label' => 'Technical',
			'operation_grants' => ['example:read' => ['getEntries']]
		], masterKeyConfigured: false);
		$body = $this->json($display);
		self::assertFalse($body['ok']);
		self::assertSame('internal_error', $body['error_code']);
		self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/D', $body['error_id']);
		self::assertSame([], $repository->listAll());
	}

	public function testUserJsonDetectsExpiredCredentialsWithoutDisclosingStoredSecrets(): void {
		[$display, $repository, $management] = $this->context('user', ['mode' => 'list']);
		$credential = $management->createForCurrentUser('Expiring', '', '', null, ['example:read'])->getCredential();
		$repository->update($credential->withManagementData(
			$credential->getLabel(), $credential->getNotificationAddress(), $credential->getNotificationLanguage(),
			time() - 30, $credential->getServiceIds()
		));
		$body = $this->json($display);
		self::assertTrue($body['ok']);
		self::assertSame('expired', $body['credentials'][0]['status']);
		self::assertArrayNotHasKey('secret_hash', $body['credentials'][0]);
		self::assertArrayNotHasKey('secret_ciphertext', $body['credentials'][0]);
	}

	public function testAdminJsonDetectsExpiredServiceCredentialsWithoutLosingPrincipal(): void {
		[$display, $repository, $management] = $this->context('admin', ['mode' => 'list']);
		$credential = $management->createForService('integration', 'Expiring', '', 'en', null, [
			'example:read' => ['getEntries']
		])->getCredential();
		$repository->update($credential->withManagementData(
			$credential->getLabel(), $credential->getNotificationAddress(), $credential->getNotificationLanguage(),
			time() - 30, $credential->getServiceIds()
		));
		$body = $this->json($display);
		self::assertTrue($body['ok']);
		self::assertSame('expired', $body['credentials'][0]['status']);
		self::assertSame('service', $body['credentials'][0]['subject_type']);
		self::assertSame('integration', $body['credentials'][0]['subject_id']);
		self::assertArrayNotHasKey('secret_ciphertext', $body['credentials'][0]);
	}

	public function testUserCreateRejectsUnknownAuthenticationModeWithoutPersisting(): void {
		[$display, $repository] = $this->context('user', [
			'mode' => 'create', 'label' => 'Invalid mode',
			'authentication_mode' => 'password', 'service_ids' => ['example:read']
		]);
		self::assertSame('invalid_input', $this->json($display)['error_code']);
		self::assertSame([], $repository->listAll());
	}

	public function testAdminUpdateRejectsMalformedResourceConstraintsWithoutMutation(): void {
		[$display, $repository, $management] = $this->context('admin', ['mode' => 'list']);
		$credential = $management->createForService('integration', 'Original', '', 'en', null, [
			'example:read' => ['getEntries']
		])->getCredential();
		[$invalid] = $this->context('admin', [
			'mode' => 'update_service', 'credential_id' => $credential->getId(),
			'label' => 'Changed', 'operation_grants' => ['example:read' => ['getEntries']],
			'resource_constraints' => 'unexpected'
		], repository: $repository);
		self::assertSame('invalid_input', $this->json($invalid)['error_code']);
		self::assertSame('Original', $repository->getById($credential->getId())?->getLabel());
	}

	public function testEncryptionAssignmentDoesNotInterpretStringAsConfirmation(): void {
		[$display, , , $encryption, $store] = $this->context('encryption', ['mode' => 'list']);
		foreach (['profile-a', 'profile-b'] as $id) {
			$encryption->saveProfileForAdmin([
				'id' => $id, 'label' => $id, 'method' => 'test-method',
				'key_id' => 'key-' . $id,
				'keys' => [['id' => 'key-' . $id, 'mode' => 'env', 'name' => 'SECRET']]
			]);
		}
		$encryption->assignForAdmin('example:store', 'profile-a');
		[$confirmation] = $this->context('encryption', [
			'mode' => 'assign', 'target_id' => 'example:store', 'profile_id' => 'profile-b',
			'confirm_change' => 'true'
		], settings: $store);
		$body = $this->json($confirmation);
		self::assertSame('validation', $body['error_code']);
		self::assertSame('assignment_confirmation', $body['validation_reason']);
		self::assertSame('profile-a', $encryption->getAssignedProfileId('example:store'));
	}

	public function testEncryptionJsonRejectsNonStringOperationValues(): void {
		foreach ([null, 100, ['mode' => 'list'], false] as $mode) {
			[$display] = $this->context('encryption', ['mode' => $mode]);
			self::assertSame('validation', $this->json($display)['error_code']);
		}
	}

	public function testUnexpectedAdminJsonFailureRecordsReferenceWithoutLeakingCauseToClient(): void {
		[$display, , , , , , $logger] = $this->context('admin', ['mode' => 'list'], throwOnAdminCheck: true);
		$entries = [];
		$logger->method('error')->willReturnCallback(static function(string $message, array $context) use (&$entries): void {
			$entries[] = ['message' => $message, 'context' => $context];
		});
		$body = $this->json($display);
		self::assertSame('internal_error', $body['error_code']);
		self::assertCount(1, $entries);
		self::assertSame(['scope' => 'keyharbor'], $entries[0]['context']);
		self::assertStringContainsString('Reference: ' . $body['error_id'], $entries[0]['message']);
		self::assertStringContainsString('RuntimeException: private authorization backend unavailable', $entries[0]['message']);
		self::assertStringContainsString('LogicException: internal upstream failure', $entries[0]['message']);
		self::assertStringNotContainsString('internal upstream failure', json_encode($body, JSON_THROW_ON_ERROR));
	}

	public function testUnexpectedUserJsonFailureRecordsReferenceWithoutLeakingCauseToClient(): void {
		[$display, , , , , , $logger] = $this->context('user', ['mode' => 'list'], throwOnAdminCheck: true);
		$entries = [];
		$logger->method('error')->willReturnCallback(static function(string $message, array $context) use (&$entries): void {
			$entries[] = ['message' => $message, 'context' => $context];
		});
		$body = $this->json($display);
		self::assertSame('internal_error', $body['error_code']);
		self::assertCount(1, $entries);
		self::assertSame(['scope' => 'keyharbor'], $entries[0]['context']);
		self::assertStringContainsString('Reference: ' . $body['error_id'], $entries[0]['message']);
		self::assertStringContainsString('LogicException: internal upstream failure', $entries[0]['message']);
		self::assertStringNotContainsString('private authorization backend unavailable', json_encode($body, JSON_THROW_ON_ERROR));
	}

	public function testAdminHtmlMissingReasonTranslationUsesGenericRequestFailedText(): void {
		[$display, , , , , $view] = $this->context('admin', [], adminAllowed: false, translations: [
			'request_failed' => 'Permission check denied'
		]);
		$assigned = [];
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		self::assertSame('<section>test</section>', $display->getOutput('html'));
		self::assertSame('Permission check denied', $assigned['error']);
	}

	public function testUserHtmlMissingErrorTranslationUsesTheDomainFailureMessage(): void {
		[$display, , , , , $view] = $this->context('user', [], authenticated: false);
		$assigned = [];
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		self::assertSame('<section>test</section>', $display->getOutput('html'));
		self::assertSame('An authenticated user is required.', $assigned['error']);
	}

	public function testEncryptionHtmlUnexpectedFailureIsLoggedAsUnavailable(): void {
		[$display, , , , , $view, $logger] = $this->context('encryption', [], throwOnAdminCheck: true);
		$assigned = [];
		$entries = [];
		$view->method('assign')->willReturnCallback(static function(string $key, mixed $value) use (&$assigned): void {
			$assigned[$key] = $value;
		});
		$logger->method('error')->willReturnCallback(static function(string $message, array $context) use (&$entries): void {
			$entries[] = [$message, $context];
		});
		self::assertSame('<section>test</section>', $display->getOutput('html'));
		self::assertSame('unavailable', $assigned['errorCode']);
		self::assertCount(1, $entries);
		self::assertSame(['scope' => 'keyharbor'], $entries[0][1]);
		self::assertStringContainsString('private authorization backend unavailable', $entries[0][0]);
	}

	public function testEncryptionJsonUnexpectedFailureIsLoggedUnderItsErrorReference(): void {
		[$display, , , , , , $logger] = $this->context('encryption', ['mode' => 'list'], throwOnAdminCheck: true);
		$entries = [];
		$logger->method('error')->willReturnCallback(static function(string $message, array $context) use (&$entries): void {
			$entries[] = [$message, $context];
		});
		$body = $this->json($display);
		self::assertSame('internal_error', $body['error_code']);
		self::assertCount(1, $entries);
		self::assertSame($body['error_id'], $entries[0][1]['error_id']);
		self::assertSame('keyharbor', $entries[0][1]['scope']);
		self::assertStringNotContainsString('internal upstream failure', json_encode($body, JSON_THROW_ON_ERROR));
	}

	/** @return array<string,mixed> */
	private function json(object $display, string $format = 'json'): array {
		$result = json_decode($display->getOutput($format), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($result);
		return $result;
	}

	/**
	 * @param array<string,mixed> $body
	 * @param array<string,mixed> $headers
	 * @param array<string,string> $translations
	 * @return array{0:object,1:ManagementTestRepository,2:CredentialManagementService,3:EncryptionProfileService,4:EncryptionTestSettingsStore,5:IMvcView,6:ILogger}
	 */
	private function context(
		string $type,
		array $body,
		array $headers = [],
		bool $adminAllowed = true,
		bool $featureAllowed = true,
		bool $authenticated = true,
		bool $masterKeyConfigured = true,
		?ManagementTestRepository $repository = null,
		?EncryptionTestSettingsStore $settings = null,
		array $translations = [],
		bool $throwOnAdminCheck = false
	): array {
		$headers = array_merge([
			'REQUEST_METHOD' => 'POST',
			'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
			'CONTENT_TYPE' => 'application/json; charset=utf-8'
		], $headers);
		$request = $this->createStub(IRequest::class);
		$request->method('server')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $headers[$key] ?? $default);
		$request->method('getJsonBody')->willReturn($body);
		$view = $this->createStub(IMvcView::class);
		$view->method('getBricks')->willReturn($translations);
		$view->method('loadTemplate')->willReturn('<section>test</section>');
		$assets = $this->createStub(IAssetResolver::class);
		$assets->method('resolve')->willReturnCallback(static fn(string $path): string => '/' . $path);
		$links = $this->createStub(ILinkTargetService::class);
		$links->method('getLink')->willReturn('/api');
		$logger = $this->createStub(ILogger::class);
		$authorization = $this->createStub(IAuthorizationService::class);
		$authorization->method('getDefaultAuthority')->willReturn('test');
		$feature = match ($type) {
			'user' => KeymanagementSettingsTab::getName(),
			'admin' => KeyharboradminSettingsTab::getName(),
			'service_admin' => KeyharborserviceadminSettingsTab::getName(),
			'encryption' => EncryptionprofilesSettingsTab::getName()
		};
		$authorization->method('decide')->willReturnCallback(static function(AuthorizationRequest $request) use ($adminAllowed, $featureAllowed, $feature, $throwOnAdminCheck): AuthorizationDecision {
			if ($request->permission->scope === 'system' && $request->permission->permission === 'admin') {
				if ($throwOnAdminCheck) throw new \RuntimeException('private authorization backend unavailable', 0, new \LogicException('internal upstream failure'));
				return $adminAllowed ? AuthorizationDecision::allow() : AuthorizationDecision::deny();
			}
			if ($request->permission->scope === 'feature' && $request->permission->permission === $feature) {
				return $featureAllowed ? AuthorizationDecision::allow() : AuthorizationDecision::deny();
			}
			return AuthorizationDecision::deny();
		});
		$user = null;
		if ($authenticated) {
			$user = new User();
			$user->id = 42;
			$user->userid = 'demo';
			$user->name = 'Test User';
			$user->email = 'demo@example.test';
			$user->lang = 'en';
		}
		$usermanager = new ManagementTestUsermanager($user, $adminAllowed);
		$config = $this->createStub(IConfiguration::class);
		$config->method('getValue')->willReturn($masterKeyConfigured ? base64_encode(str_repeat('m', 32)) : null);
		$resolver = $this->createStub(IConfigValueResolver::class);
		$resolver->method('resolve')->willReturn(str_repeat('e', 40));
		$crypt = $this->createStub(ICrypt::class);
		$crypt->method('getMethod')->willReturn('test-method');
		$classMap = new EncryptionTestClassMap([
			ICredentialServiceProvider::class => [new ManagementTestProvider()],
			IEncryptionTargetProvider::class => [new EncryptionExampleTargetProvider()],
			ICrypt::class => [$crypt]
		]);
		$repository ??= new ManagementTestRepository();
		$settings ??= new EncryptionTestSettingsStore();
		$cipher = new CredentialSecretCipher($config, $resolver);
		$management = new CredentialManagementService(
			$usermanager,
			$authorization,
			$repository,
			new CredentialTokenService(),
			new CredentialServiceCatalog($classMap),
			$cipher
		);
		$encryption = new EncryptionProfileService($settings, $resolver, $classMap, $cipher, $management);
		$display = match ($type) {
			'user' => new KeyManagementDisplay($request, $view, $assets, $links, $logger, $management, $authorization),
			'admin' => new KeyHarborAdminDisplay($request, $view, $assets, $links, $logger, $management, $authorization),
			'service_admin' => new KeyHarborServiceAdminDisplay($request, $view, $assets, $links, $logger, $management, $authorization),
			'encryption' => new EncryptionProfilesDisplay($request, $view, $assets, $links, $logger, $management, $encryption, $authorization)
		};
		return [$display, $repository, $management, $encryption, $settings, $view, $logger];
	}
}

require_once dirname(__DIR__) . '/Service/ManagementTestUsermanager.php';
require_once dirname(__DIR__) . '/Service/ManagementTestRepository.php';
require_once dirname(__DIR__) . '/Service/ManagementTestProvider.php';
require_once dirname(__DIR__) . '/Service/EncryptionTestClassMap.php';
require_once dirname(__DIR__) . '/Service/EncryptionExampleTargetProvider.php';
require_once dirname(__DIR__) . '/Service/EncryptionTestSettingsStore.php';
