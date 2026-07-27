<?php declare(strict_types=1);

namespace KeyHarbor\Test\Display;

use PHPUnit\Framework\TestCase;

final class AjaxOnlyUiTest extends TestCase {

	public function testManagementTemplatesContainNoFormsSubmitControlsOrAnchors(): void {
		$pluginRoot = dirname(__DIR__, 2);
		$templates = [
			$pluginRoot . '/tpl/Display/KeyManagementDisplay.php',
			$pluginRoot . '/tpl/Display/KeyHarborAdminDisplay.php'
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
			$pluginRoot . '/assets/keyharbor/keyharboradmin.js'
		] as $script) {
			$content = (string)file_get_contents($script);
			$this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $content);
		}
	}
}
