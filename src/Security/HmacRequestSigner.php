<?php declare(strict_types=1);

namespace KeyHarbor\Security;

use CredentialFoundation\Dto\HmacAuthenticationRequest;

/**
 * Builds and verifies the canonical KeyHarbor HMAC-SHA256 signature.
 */
final class HmacRequestSigner {

	public function createCanonicalRequest(HmacAuthenticationRequest $request): string {
		return implode("\n", [
			$request->getMethod(),
			$request->getPath(),
			$request->getQueryString(),
			(string)$request->getTimestamp(),
			$request->getNonce(),
			hash('sha256', $request->getBody())
		]);
	}

	public function sign(HmacAuthenticationRequest $request, string $secret): string {
		return hash_hmac('sha256', $this->createCanonicalRequest($request), $secret);
	}

	public function verify(HmacAuthenticationRequest $request, string $secret): bool {
		$signature = strtolower(trim($request->getSignature()));
		if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
			return false;
		}

		return hash_equals($this->sign($request, $secret), $signature);
	}
}
