<?php declare(strict_types=1);

namespace KeyHarbor\Test\Display;

use PHPUnit\Framework\TestCase;

final class AjaxOnlyUiTest extends TestCase {

	public function testManagementTemplatesContainNoFormsSubmitControlsOrAnchors(): void {
		$pluginRoot = dirname(__DIR__, 2);
		$templates = [
			$pluginRoot . '/tpl/Display/KeyManagementDisplay.php',
			$pluginRoot . '/tpl/Display/KeyHarborAdminDisplay.php',
			$pluginRoot . '/tpl/Display/EncryptionProfilesDisplay.php'
		];

		foreach ($templates as $template) {
			$content = (string)file_get_contents($template);
			$this->assertStringNotContainsString('<form', strtolower($content));
			$this->assertStringNotContainsString('type="submit"', strtolower($content));
			$this->assertDoesNotMatchRegularExpression('/<a\\s/i', $content);
		}
	}

	public function testDeleteActionsRemainAjaxOnly(): void {
		$pluginRoot = dirname(__DIR__, 2);
		$userScript = (string)file_get_contents($pluginRoot . '/assets/keyharbor/keymanagement.js');
		$adminScript = (string)file_get_contents($pluginRoot . '/assets/keyharbor/keyharboradmin.js');

		$this->assertStringContainsString("mode: 'delete'", $userScript);
		$this->assertStringContainsString("mode: 'delete'", $adminScript);
		$this->assertStringContainsString("if(action === 'delete')", $userScript);
		$this->assertStringContainsString("button.dataset.action === 'delete'", $adminScript);
	}

	public function testDisplaysUseCanonicalAssetNames(): void {
		$pluginRoot = dirname(__DIR__, 2);
		$userDisplay = (string)file_get_contents($pluginRoot . '/src/Display/KeyManagementDisplay.php');
		$adminDisplay = (string)file_get_contents($pluginRoot . '/src/Display/KeyHarborAdminDisplay.php');

		$this->assertStringContainsString('assets/keyharbor/keymanagement.js', $userDisplay);
		$this->assertStringContainsString('assets/keyharbor/keyharboradmin.js', $adminDisplay);
		$this->assertStringNotContainsString('-v061.js', $userDisplay . $adminDisplay);
	}

	public function testAjaxClientsSendRequestedWithHeader(): void {
		$pluginRoot = dirname(__DIR__, 2);
		foreach ([
			$pluginRoot . '/assets/keyharbor/keymanagement.js',
			$pluginRoot . '/assets/keyharbor/keyharboradmin.js',
			$pluginRoot . '/assets/keyharbor/encryptionprofiles.js'
		] as $script) {
			$content = (string)file_get_contents($script);
			$this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $content);
		}
	}
	public function testEncryptionConfigurationErrorsAreActionableWithinTheEditor(): void {
		$root = dirname(__DIR__, 2);
		$display = (string)file_get_contents($root . '/src/Display/EncryptionProfilesDisplay.php');
		$script = (string)file_get_contents($root . '/assets/keyharbor/encryptionprofiles.js');
		$template = (string)file_get_contents($root . '/tpl/Display/EncryptionProfilesDisplay.php');
		self::assertStringContainsString('catch (CredentialHmacConfigurationException $exception)', $display);
		self::assertStringContainsString("'error_code' => 'hmac_configuration'", $display);
		self::assertStringContainsString('data-editor-notice', $template);
		self::assertStringContainsString("editor.querySelector('[data-editor-notice]')", $script);
		self::assertStringContainsString("'access_denied', 'hmac_configuration'", $script);
		foreach (glob($root . '/lang/Display/*.ini') as $languageFile) {
			$translations = (string)file_get_contents($languageFile);
			self::assertStringContainsString('error_hmac_configuration =', $translations);
			self::assertStringContainsString('keyharbor.hmac_master_key', $translations);
		}
	}

	public function testStoredEncryptionProfilesAndKeysHaveNoDeletionEndpoint(): void {
		$root = dirname(__DIR__, 2);
		$display = (string)file_get_contents($root . '/src/Display/EncryptionProfilesDisplay.php');
		$service = (string)file_get_contents($root . '/src/Service/EncryptionProfileService.php');
		$script = (string)file_get_contents($root . '/assets/keyharbor/encryptionprofiles.js');
		self::assertStringNotContainsString("$" . "mode === 'delete'", $display);
		self::assertStringNotContainsString('deleteProfileForAdmin(', $service);
		self::assertStringNotContainsString('removeProfile(', $script);
		self::assertStringNotContainsString("button(t('delete')", $script);
		self::assertStringContainsString('rotateFixedKeyForAdmin', $display);
		self::assertStringContainsString('Persisted encryption keys cannot be removed.', $service);
		self::assertStringContainsString('keyIdInput.readOnly = locked', $script);
		self::assertStringContainsString('select.disabled = locked', $script);
		self::assertStringContainsString("!entry.dataset.persisted", $script);
		self::assertStringNotContainsString("button(t('remove_key')", $script);
	}

	public function testEncryptionAddKeyButtonUsesAvailableTranslationsInEveryLanguage(): void {
		$root = dirname(__DIR__, 2);
		$script = (string)file_get_contents($root . '/assets/keyharbor/encryptionprofiles.js');
		self::assertStringContainsString("if(action.dataset.action === 'add-key')", $script);
		self::assertStringContainsString("discardKey.setAttribute('aria-label', t('cancel')", $script);
		self::assertStringNotContainsString("t('remove_key')", $script);

		preg_match_all("/\\bt\\('([a-z][a-z0-9_]*)'\\)/", $script, $matches);
		foreach (glob($root . '/lang/Display/*.ini') as $languageFile) {
			$sections = parse_ini_file($languageFile, true, INI_SCANNER_RAW);
			self::assertIsArray($sections);
			$translations = $sections['keyharbor_encryption_display'] ?? [];
			foreach (array_unique($matches[1]) as $key) {
				self::assertArrayHasKey($key, $translations, basename($languageFile) . ': ' . $key);
			}
		}
	}

	public function testEncryptionDialogUsesEqualColumnsWithMobileStacking(): void {
		$root = dirname(__DIR__, 2);
		$style = (string)file_get_contents($root . '/assets/keyharbor/keyharbor.css');
		self::assertStringContainsString(".keyharbor-encryption .keyharbor-form-row {\n\tgrid-template-columns: repeat(2, minmax(0, 1fr));", $style);
		self::assertStringContainsString("@media (max-width: 640px)", $style);
		self::assertStringContainsString("\t.keyharbor-encryption .keyharbor-form-row,", $style);
	}

	public function testEncryptionManagementUsesExactlyOneDedicatedDisplay(): void {
		$root = dirname(__DIR__, 2);
		$display = (string)file_get_contents($root . '/src/Display/EncryptionProfilesDisplay.php');
		$plugin = (string)file_get_contents($root . '/src/KeyHarborPlugin.php');
		self::assertStringContainsString("return 'encryptionprofilesdisplay'", $display);
		self::assertStringContainsString('assets/keyharbor/encryptionprofiles.js', $display);
		self::assertStringContainsString('IEncryptionProfileService::class', $plugin);
	}
}
