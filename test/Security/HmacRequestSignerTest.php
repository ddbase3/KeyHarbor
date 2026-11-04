<?php declare(strict_types=1);

namespace KeyHarbor\Test\Security;

use CredentialFoundation\Dto\HmacAuthenticationRequest;
use KeyHarbor\Security\HmacRequestSigner;
use PHPUnit\Framework\TestCase;

final class HmacRequestSignerTest extends TestCase {

	public function testCanonicalRequestPreservesFieldOrderAndHashesRawBody(): void {
		$request = $this->request('POST', '/v1/items', 'page=2&scope=x', 1700000000, 'nonce-42', "\x00binary\nbody");
		$signer = new HmacRequestSigner();

		self::assertSame(implode("\n", [
			'POST',
			'/v1/items',
			'page=2&scope=x',
			'1700000000',
			'nonce-42',
			hash('sha256', "\x00binary\nbody")
		]), $signer->createCanonicalRequest($request));
	}

	public function testSignatureUsesHmacSha256OfCanonicalRequest(): void {
		$request = $this->request();
		$signer = new HmacRequestSigner();

		self::assertSame(
			hash_hmac('sha256', $signer->createCanonicalRequest($request), "secret\x00material"),
			$signer->sign($request, "secret\x00material")
		);
		self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $signer->sign($request, 'secret'));
	}

	public function testVerifiesValidSignatureCaseInsensitivelyWithOuterWhitespace(): void {
		$signer = new HmacRequestSigner();
		$unsigned = $this->request();
		$signature = strtoupper($signer->sign($unsigned, 'secret'));

		self::assertTrue($signer->verify($this->request(signature: " \n" . $signature . "\t"), 'secret'));
	}

	public function testRejectsMalformedSignaturesBeforeComparison(): void {
		$signer = new HmacRequestSigner();
		foreach (['invalid', str_repeat('a', 63), str_repeat('a', 65), str_repeat('g', 64), str_repeat('a', 32) . ' ' . str_repeat('b', 32)] as $invalid) {
			self::assertFalse($signer->verify($this->request(signature: $invalid), 'secret'));
		}
	}

	public function testRejectsWrongSecretAndChangedRequestComponents(): void {
		$signer = new HmacRequestSigner();
		$original = $this->request();
		$signature = $signer->sign($original, 'secret');
		self::assertFalse($signer->verify($this->request(signature: $signature), 'other-secret'));

		$changes = [
			['PUT', '/v1/items', 'page=2', 1700000000, 'nonce-42', 'body'],
			['POST', '/v1/other', 'page=2', 1700000000, 'nonce-42', 'body'],
			['POST', '/v1/items', 'page=3', 1700000000, 'nonce-42', 'body'],
			['POST', '/v1/items', 'page=2', 1700000001, 'nonce-42', 'body'],
			['POST', '/v1/items', 'page=2', 1700000000, 'other-nonce', 'body'],
			['POST', '/v1/items', 'page=2', 1700000000, 'nonce-42', 'body ']
		];
		foreach ($changes as [$method, $path, $query, $timestamp, $nonce, $body]) {
			self::assertFalse($signer->verify($this->request($method, $path, $query, $timestamp, $nonce, $body, $signature), 'secret'));
		}
	}

	public function testCanonicalRequestDoesNotContainRawBodyData(): void {
		$signer = new HmacRequestSigner();
		$canonical = $signer->createCanonicalRequest($this->request(body: 'private-payload-123'));

		self::assertStringNotContainsString('private-payload-123', $canonical);
		self::assertStringEndsWith(hash('sha256', 'private-payload-123'), $canonical);
	}

	private function request(
		string $method = 'POST',
		string $path = '/v1/items',
		string $query = 'page=2',
		int $timestamp = 1700000000,
		string $nonce = 'nonce-42',
		string $body = 'body',
		string $signature = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
	): HmacAuthenticationRequest {
		return new HmacAuthenticationRequest(
			'b3k_' . str_repeat('a', 20) . '_' . str_repeat('b', 43),
			$method,
			$path,
			$query,
			$timestamp,
			$nonce,
			$signature,
			$body
		);
	}
}
