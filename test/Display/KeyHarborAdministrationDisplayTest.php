<?php declare(strict_types=1);

/** Standalone regression test for all KeyHarbor admin views and ClientStack dialogs. */

$root = dirname(__DIR__, 2);
$assert = static function(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
};

$renderer = new class {
	public array $_ = [];

	public function render(string $path, array $data): string {
		$this->_ = $data;
		ob_start();
		try {
			include $path;
			return (string)ob_get_clean();
		} catch (Throwable $exception) {
			ob_end_clean();
			throw $exception;
		}
	}
};

$adminClass = (string)file_get_contents($root . '/src/Display/KeyHarborAdminDisplay.php');
$serviceClass = (string)file_get_contents($root . '/src/Display/KeyHarborServiceAdminDisplay.php');
$adminScript = (string)file_get_contents($root . '/assets/keyharbor/keyharboradmin.js');
$assert(str_contains($serviceClass, 'extends KeyHarborAdminDisplay'), 'Service display must reuse the existing administrator implementation.');
$assert(str_contains($serviceClass, "return 'keyharborserviceadmindisplay'"), 'Service display name is not discoverable.');
$assert(str_contains($adminClass, "'name' => static::getName()"), 'AJAX link must resolve for both display names.');
$assert(str_contains($adminClass, '$this->managementService->listAllForAdmin()'), 'Both displays must enforce existing admin policy.');
$assert(str_contains($adminClass, '$credential->isServiceCredential()'), 'Dedicated service list must filter user credentials.');
$assert(str_contains($adminClass, "'create_service'"), 'Existing technical credential creation is missing.');
$assert(str_contains($adminClass, "'update_service'"), 'Existing technical credential editing is missing.');
$assert(str_contains($adminClass, "'rotate_service'"), 'Existing technical credential rotation is missing.');
$assert(str_contains($adminScript, 'dialogModule.createStandardDialog('), 'ClientStack dialogs have been removed.');
$assert(str_contains($adminScript, "mode: creating ? 'create_service' : 'update_service'"), 'Service editor does not submit via existing AJAX API.');

$languages = glob($root . '/lang/Display/*.ini');
$assert(count($languages) === 12, 'Expected all 12 supported languages.');
$keys = ['service_page_eyebrow', 'service_page_title', 'service_page_lead', 'service_page_help', 'service_page_empty_title', 'service_page_empty_text'];
$templates = [
	'KeyManagementDisplay.php' => ['keyharbor_management_display', 'data-keyharbor-user'],
	'KeyHarborAdminDisplay.php' => ['keyharbor_admin_display', 'data-keyharbor-admin'],
	'EncryptionProfilesDisplay.php' => ['keyharbor_encryption_display', 'data-keyharbor-encryption']
];
$rendered = 0;
foreach ($languages as $language) {
	$sections = parse_ini_file($language, true, INI_SCANNER_RAW);
	$assert(is_array($sections), 'Cannot parse language file: ' . basename($language));
	$adminTranslations = $sections['keyharbor_admin_display'] ?? [];
	foreach ($keys as $key) {
		$assert(trim((string)($adminTranslations[$key] ?? '')) !== '', basename($language) . ': missing ' . $key);
	}
	$navigation = parse_ini_file($root . '/lang/Administration/' . basename($language), true, INI_SCANNER_RAW);
	$assert(trim((string)($navigation['administration']['base3_admin_subtab_keyharborserviceadmindisplay'] ?? '')) !== '', basename($language) . ': missing navigation title.');

	foreach ($templates as $file => [$section, $selector]) {
		foreach ([false, true] as $errorState) {
			foreach ($file === 'KeyHarborAdminDisplay.php' ? [false, true] : [false] as $serviceOnly) {
				$data = [
					'error' => $errorState ? 'Access denied' : '',
					'errorCode' => $errorState ? 'access_denied' : '',
					'profile' => ['name' => 'Test User', 'email' => 'test@example.invalid'],
					'translations' => $sections[$section],
					'service' => '/api/credentials',
					'cssUrl' => '/assets/keyharbor.css',
					'scriptUrl' => '/assets/keyharbor.js',
					'dialogCssUrl' => '/assets/modulardialog.css',
					'dialogJsUrl' => '/assets/modulardialog/index.js',
					'serviceOnly' => $serviceOnly
				];
				$html = $renderer->render($root . '/tpl/Display/' . $file, $data);
				$assert(str_contains($html, $selector), $file . ': missing page root.');
				$assert(str_contains($html, 'data-dialog-url='), $file . ': ClientStack module URL missing.');
				$assert(str_contains($html, '<div class="keyharbor-hero">'), $file . ': embedded admin header must be a div.');
				$assert(!str_contains($html, '<dialog'), $file . ': native dialog markup is not allowed.');
				if ($file === 'KeyHarborAdminDisplay.php') {
					$title = $serviceOnly ? $adminTranslations['service_page_title'] : $adminTranslations['title'];
					$escaped = htmlspecialchars((string)$title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
					$assert(str_contains($html, '<h2>' . $escaped . '</h2>'), basename($language) . ': incorrect service/admin title.');
					$assert(str_contains($html, 'data-service-only="' . ($serviceOnly ? '1' : '0') . '"'), 'Admin display mode not assigned.');
					if (!$errorState) {
						$assert(str_contains($html, 'data-service-editor-content'), 'Service editor is missing.');
						$assert(str_contains($html, 'data-service-token-content'), 'One-time credential dialog is missing.');
						$assert(str_contains($html, 'data-action="create-service"'), 'Service creation entry point is missing.');
					}
				}
				$rendered++;
			}
		}
	}
}

echo 'KeyHarbor administration displays: OK (' . count($languages) . ' languages, ' . $rendered . " renders, ClientStack dialogs).\n";
