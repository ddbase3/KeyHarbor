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

namespace Base3\KeyHarbor\Test\Display;

use PHPUnit\Framework\TestCase;

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
