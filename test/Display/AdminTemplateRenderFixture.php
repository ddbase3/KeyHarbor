<?php declare(strict_types=1);

namespace KeyHarbor\Test\Display;

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
