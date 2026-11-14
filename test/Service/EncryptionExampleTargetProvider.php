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
use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationDecision;
use Base3\CoreFoundation\Configuration\Api\IConfiguration;
use Base3\CoreFoundation\ConfigValue\Api\IConfigValueResolver;
use Base3\CoreFoundation\Crypt\Api\ICrypt;
use Base3\CoreFoundation\Settings\Api\ISettingsStore;
use Base3\CoreFoundation\Usermanager\Api\IUsermanager;
use Base3\CoreFoundation\Usermanager\User;
use Base3\CredentialFoundation\Api\IEncryptionTargetProvider;
use Base3\CredentialFoundation\Dto\EncryptionTargetDefinition;
use InvalidArgumentException;
use Base3\KeyHarbor\Api\ICredentialRepository;
use Base3\KeyHarbor\Exception\CredentialHmacConfigurationException;
use Base3\KeyHarbor\Security\CredentialSecretCipher;
use Base3\KeyHarbor\Security\CredentialTokenService;
use Base3\KeyHarbor\Service\CredentialManagementService;
use Base3\KeyHarbor\Service\CredentialServiceCatalog;
use Base3\KeyHarbor\Service\EncryptionProfileService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EncryptionExampleTargetProvider implements IEncryptionTargetProvider {
	public static function getName(): string { return 'encryptionexampletargetprovider'; }
	public function getTargets(): array { return [new EncryptionTargetDefinition('example:store', 'Example store')]; }
}
