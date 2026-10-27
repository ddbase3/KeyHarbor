<?php declare(strict_types=1);

namespace KeyHarbor\Test\Display;

use PHPUnit\Framework\TestCase;

final class AjaxOnlyUiTest extends TestCase {

	public function testTechnicalCredentialTranslationsExistInAllSupportedLanguages(): void {
		$pluginRoot = dirname(__DIR__, 2);
		$javascript = (string)file_get_contents($pluginRoot . '/assets/keyharbor/keyharboradmin.js');
		$template = (string)file_get_contents($pluginRoot . '/tpl/Display/KeyHarborAdminDisplay.php');
		preg_match_all('/\bt\(\x27([a-z0-9_]+)\x27/', $javascript, $jsKeys);
		preg_match_all('/\$t\(\x27([a-z0-9_]+)\x27/', $template, $templateKeys);
		$keys = array_unique(array_filter(array_merge($jsKeys[1], $templateKeys[1]), fn(string $key): bool => $key !== 'error_'));
		$files = glob($pluginRoot . '/lang/Display/*.ini');
		self::assertCount(12, $files);
		foreach ($files as $file) {
			$translations = parse_ini_file($file, true, INI_SCANNER_RAW);
			self::assertIsArray($translations, basename($file));
			$admin = $translations['keyharbor_admin_display'];
			foreach ($keys as $key) {
				self::assertNotEmpty($admin[$key] ?? null, basename($file) . ':' . $key);
			}
		}
	}

	public function testTechnicalCredentialDialogsRenderInAllSupportedLanguages(): void {
		$pluginRoot = dirname(__DIR__, 2);
		foreach (glob($pluginRoot . '/lang/Display/*.ini') as $file) {
			$translations = parse_ini_file($file, true, INI_SCANNER_RAW)['keyharbor_admin_display'];
			$renderer = new AdminTemplateRenderFixture([
				'error' => '',
				'translations' => $translations,
				'service' => '/index.php?name=keyharboradmindisplay',
				'cssUrl' => '/assets/keyharbor.css',
				'scriptUrl' => '/assets/keyharboradmin.js',
				'dialogCssUrl' => '/assets/modulardialog.css',
				'dialogJsUrl' => '/assets/modulardialog/index.js'
			]);
			$html = $renderer->render($pluginRoot . '/tpl/Display/KeyHarborAdminDisplay.php');
			self::assertStringContainsString('data-service-editor-content', $html, basename($file));
			self::assertStringContainsString('data-service-token-content', $html, basename($file));
			self::assertStringContainsString($translations['service_subject'], $html, basename($file));
		}
	}


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

	public function testEveryDialogUsesClientStackInsteadOfNativeDialogMarkup(): void {
		$root = dirname(__DIR__, 2);
		$manifest = json_decode((string)file_get_contents($root . '/base3.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertArrayHasKey('ClientStack', $manifest['dependencies']);
		foreach (['KeyManagementDisplay', 'KeyHarborAdminDisplay', 'EncryptionProfilesDisplay'] as $displayName) {
			$display = (string)file_get_contents($root . '/src/Display/' . $displayName . '.php');
			$template = (string)file_get_contents($root . '/tpl/Display/' . $displayName . '.php');
			self::assertStringContainsString('plugin/ClientStack/assets/modulardialog/styles/modulardialog.css', $display);
			self::assertStringContainsString('plugin/ClientStack/assets/modulardialog/index.js', $display);
			self::assertStringContainsString('data-dialog-url=', $template);
			self::assertStringNotContainsString('<dialog', $template);
		}
		foreach (['keymanagement.js', 'keyharboradmin.js', 'encryptionprofiles.js'] as $scriptName) {
			$script = (string)file_get_contents($root . '/assets/keyharbor/' . $scriptName);
			self::assertStringContainsString('await import(new URL(root.dataset.dialogUrl, document.baseURI).href)', $script);
			self::assertStringContainsString('createStandardDialog(', $script);
			self::assertStringNotContainsString('.showModal(', $script);
		}
		$script = (string)file_get_contents($root . '/assets/keyharbor/encryptionprofiles.js');
		self::assertStringContainsString("editorContent.addEventListener('click', handleClick)", $script);
		self::assertStringContainsString("if(action.dataset.action === 'add-key')", $script);
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
		self::assertStringContainsString("editorContent.querySelector('[data-editor-notice]')", $script);
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

	public function testFixedSecretIsGeneratedOnServerWithoutSecretFormField(): void {
		$root = dirname(__DIR__, 2);
		$script = (string)file_get_contents($root . '/assets/keyharbor/encryptionprofiles.js');
		$service = (string)file_get_contents($root . '/src/Service/EncryptionProfileService.php');
		self::assertStringNotContainsString("field(t('fixed_secret')", $script);
		self::assertStringNotContainsString("data-prop=\"secret\"", $script);
		self::assertStringContainsString("const autoActive = !state.editing", $script);
		self::assertStringContainsString("$" . "incoming === []", $service);
		self::assertStringContainsString('createEncryptedFixedKey()', $service);
		self::assertStringContainsString('random_bytes(32)', $service);
		foreach (glob($root . '/lang/Display/*.ini') as $file) {
			$translations = parse_ini_file($file, true, INI_SCANNER_RAW)['keyharbor_encryption_display'];
			self::assertNotEmpty($translations['fixed_key_id_hint'] ?? null, basename($file));
			self::assertNotEmpty($translations['validation_fixed_managed'] ?? null, basename($file));
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

/** Minimal template fixture: templates are rendered by IMvcView in production. */
final class AdminTemplateRenderFixture {

	public array $_;

	public function __construct(array $data) { $this->_ = $data; }

	public function render(string $file): string {
		ob_start();
		try {
			include $file;
			return (string)ob_get_clean();
		} catch (\Throwable $exception) {
			ob_end_clean();
			throw $exception;
		}
	}
}
