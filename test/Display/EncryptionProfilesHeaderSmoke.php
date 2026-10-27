<?php declare(strict_types=1);

// The existing KeyHarbor header smoke test now covers all three embedded administration views.
$pluginRoot = dirname(__DIR__, 2);
$css = (string)file_get_contents($pluginRoot . '/assets/keyharbor/keyharbor.css');
$check = static function(bool $condition, string $description): void {
	if (!$condition) {
		throw new RuntimeException($description);
	}
};

$check(substr_count($css, '.keyharbor-hero h2') === 2, 'Shared hero h2 styling missing.');
$check(str_contains($css, '[data-service-operations] > fieldset'), 'Service credential editor styles must remain.');

$fixture = new class {
	public array $_ = [];

	public function render(string $path, array $vars): string {
		$this->_ = $vars;
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

$pages = [
	'KeyHarborAdminDisplay.php' => ['keyharbor_admin_display', 'data-keyharbor-admin', 'data-action="refresh"', 'data-action="create-service"'],
	'KeyManagementDisplay.php' => ['keyharbor_management_display', 'data-keyharbor-user', 'data-action="create"', 'data-action="refresh"'],
	'EncryptionProfilesDisplay.php' => ['keyharbor_encryption_display', 'data-keyharbor-encryption', 'data-action="refresh"', 'data-action="create"'],
];
$languages = glob($pluginRoot . '/lang/Display/*.ini');
$check(count($languages) === 12, 'Expected 12 KeyHarbor display locales.');
$rendered = 0;

foreach ($pages as $file => [$languageSection, $rootAttribute, $firstAction, $secondAction]) {
	$templatePath = $pluginRoot . '/tpl/Display/' . $file;
	$template = (string)file_get_contents($templatePath);
	$check(substr_count($template, '<div class="keyharbor-hero">') === 1, $file . ': neutral intro missing.');
	$check(!str_contains($template, '<header class="keyharbor-hero">'), $file . ': obsolete hero header.');
	$check(!str_contains($template, '<h1>'), $file . ': obsolete embedded page h1.');
	$check(str_contains($template, '<h2><?php echo $e($t('), $file . ': translated h2 missing.');
	$check(str_contains($template, '<header class="keyharbor-dialog-header">'), $file . ': dialog header lost.');
	$check(!str_contains($template, 'role="region"') && !str_contains($template, 'role="banner"'), $file . ': unexpected landmark.');

	foreach ($languages as $language) {
		$sections = parse_ini_file($language, true, INI_SCANNER_RAW);
		$check(is_array($sections), 'Cannot parse ' . $language);
		$translations = $sections[$languageSection] ?? null;
		$check(is_array($translations), $file . ': locale section missing in ' . $language);
		$check(trim((string)($translations['title'] ?? '')) !== '', $file . ': translated title missing in ' . $language);

		foreach (['', 'access_denied'] as $errorCode) {
			$vars = [
				'error' => $errorCode,
				'errorCode' => $errorCode,
				'translations' => $translations,
				'profile' => ['name' => 'Test User', 'email' => 'test@example.invalid'],
				'service' => '/service',
				'cssUrl' => '/assets/keyharbor.css',
				'scriptUrl' => '/assets/keyharbor.js',
			];
			$output = $fixture->render($templatePath, $vars);
			$check(substr_count($output, '<div class="keyharbor-hero">') === 1, $file . ': render intro missing: ' . $language);
			$check(!str_contains($output, '<header class="keyharbor-hero">'), $file . ': render has obsolete header: ' . $language);
			$title = htmlspecialchars((string)$translations['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$check(str_contains($output, '<h2>' . $title . '</h2>'), $file . ': localized heading missing: ' . $language);
			$check(str_contains($output, $rootAttribute), $file . ': app root missing.');
			$check(str_contains($output, 'data-service-url='), $file . ': service URL missing.');
			$check(str_contains($output, 'data-strings='), $file . ': translations payload missing.');
			$check(str_contains($output, '<header class="keyharbor-dialog-header">') === ($errorCode === '' || $file === 'KeyManagementDisplay.php'), $file . ': dialog rendering changed.');
			$check(str_contains($output, $firstAction) === ($errorCode === '' || $file !== 'EncryptionProfilesDisplay.php'), $file . ': first action unexpectedly changed.');
			$check(str_contains($output, $secondAction) === ($errorCode === '' || $file === 'KeyHarborAdminDisplay.php'), $file . ': second action unexpectedly changed.');
			$rendered++;
		}
	}
}

echo 'KeyHarbor administration headings: OK (' . count($pages) . ' views, ' . count($languages) . ' languages, ' . $rendered . " normal/error renders).\n";
