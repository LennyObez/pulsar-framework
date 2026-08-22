<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ApiSigning;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Security\ApiSigning\RequestSigner;
use Pulsar\Security\ApiSigning\SignatureVerificationResult;
use Pulsar\Security\ApiSigning\SignedRequestComponents;

use function str_repeat;

#[CoversClass(RequestSigner::class)]
#[CoversClass(SignatureVerificationResult::class)]
#[CoversClass(SignedRequestComponents::class)]
final class RequestSignerTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        // 32-byte key (meets SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN)
        $this->key = str_repeat('k', 32);
    }

    #[Test]
    public function sign_adds_required_headers(): void
    {
        $signer = new RequestSigner($this->key, 'test-key-1');

        $request = new ServerRequest(method: 'POST', uri: '/api/v1/data', body: '{"foo":"bar"}');
        $signed = $signer->sign($request);

        self::assertNotEmpty($signed->getHeaderLine(RequestSigner::HEADER_SIGNATURE));
        self::assertNotEmpty($signed->getHeaderLine(RequestSigner::HEADER_TIMESTAMP));
        self::assertSame('test-key-1', $signed->getHeaderLine(RequestSigner::HEADER_KEY_ID));
    }

    #[Test]
    public function verify_accepts_valid_signature(): void
    {
        $signer = new RequestSigner($this->key, 'key-1');

        $request = new ServerRequest(method: 'GET', uri: '/api/status');
        $signed = $signer->sign($request);

        $result = $signer->verify($signed);

        self::assertTrue($result->valid);
        self::assertSame('', $result->reason);
    }

    #[Test]
    public function verify_rejects_missing_headers(): void
    {
        $signer = new RequestSigner($this->key, 'key-1');

        $request = new ServerRequest(method: 'GET', uri: '/api/status');
        $result = $signer->verify($request);

        self::assertFalse($result->valid);
        self::assertSame('Missing signature headers', $result->reason);
    }

    #[Test]
    public function verify_rejects_wrong_key_id(): void
    {
        $signer1 = new RequestSigner($this->key, 'key-1');
        $signer2 = new RequestSigner($this->key, 'key-2');

        $signed = $signer1->sign(new ServerRequest(method: 'GET', uri: '/'));
        $result = $signer2->verify($signed);

        self::assertFalse($result->valid);
        self::assertStringContainsString('Unknown key ID', $result->reason);
    }

    #[Test]
    public function verify_rejects_tampered_body(): void
    {
        $signer = new RequestSigner($this->key, 'key-1');

        $request = new ServerRequest(method: 'POST', uri: '/api/data', body: '{"amount":100}');
        $signed = $signer->sign($request);

        // Tamper with the body but keep the signature headers
        $tampered = new ServerRequest(
            method: 'POST',
            uri: '/api/data',
            headers: [
                RequestSigner::HEADER_SIGNATURE => $signed->getHeaderLine(RequestSigner::HEADER_SIGNATURE),
                RequestSigner::HEADER_TIMESTAMP => $signed->getHeaderLine(RequestSigner::HEADER_TIMESTAMP),
                RequestSigner::HEADER_KEY_ID => $signed->getHeaderLine(RequestSigner::HEADER_KEY_ID),
            ],
            body: '{"amount":999999}',
        );

        $result = $signer->verify($tampered);

        self::assertFalse($result->valid);
        self::assertSame('Signature mismatch', $result->reason);
    }

    #[Test]
    public function verify_rejects_expired_timestamp(): void
    {
        $signer = new RequestSigner($this->key, 'key-1', maxClockSkewSeconds: 1);

        // Create a request with an old timestamp
        $oldTimestamp = new DateTimeImmutable('-10 minutes')->format('c');

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/status',
            headers: [
                RequestSigner::HEADER_SIGNATURE => 'deadbeef',
                RequestSigner::HEADER_TIMESTAMP => $oldTimestamp,
                RequestSigner::HEADER_KEY_ID => 'key-1',
            ],
        );

        $result = $signer->verify($request);

        self::assertFalse($result->valid);
        self::assertStringContainsString('Timestamp outside allowed skew', $result->reason);
    }

    #[Test]
    public function verify_rejects_invalid_timestamp_format(): void
    {
        $signer = new RequestSigner($this->key, 'key-1');

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/status',
            headers: [
                RequestSigner::HEADER_SIGNATURE => 'deadbeef',
                RequestSigner::HEADER_TIMESTAMP => 'not-a-date',
                RequestSigner::HEADER_KEY_ID => 'key-1',
            ],
        );

        $result = $signer->verify($request);

        self::assertFalse($result->valid);
        self::assertSame('Invalid timestamp format', $result->reason);
    }

    #[Test]
    public function different_methods_produce_different_signatures(): void
    {
        $signer = new RequestSigner($this->key, 'key-1');

        $get = $signer->sign(new ServerRequest(method: 'GET', uri: '/api'));
        $post = $signer->sign(new ServerRequest(method: 'POST', uri: '/api'));

        self::assertNotSame(
            $get->getHeaderLine(RequestSigner::HEADER_SIGNATURE),
            $post->getHeaderLine(RequestSigner::HEADER_SIGNATURE),
        );
    }

    #[Test]
    public function different_paths_produce_different_signatures(): void
    {
        $signer = new RequestSigner($this->key, 'key-1');

        $a = $signer->sign(new ServerRequest(method: 'GET', uri: '/api/a'));
        $b = $signer->sign(new ServerRequest(method: 'GET', uri: '/api/b'));

        self::assertNotSame(
            $a->getHeaderLine(RequestSigner::HEADER_SIGNATURE),
            $b->getHeaderLine(RequestSigner::HEADER_SIGNATURE),
        );
    }

    #[Test]
    public function short_key_throws(): void
    {
        $signer = new RequestSigner('short', 'key-1');

        $this->expectException(InvalidArgumentException::class);
        (void) $signer->sign(new ServerRequest(method: 'GET', uri: '/'));
    }

    #[Test]
    public function canonical_string_format(): void
    {
        $components = new SignedRequestComponents(
            method: 'POST',
            path: '/api/data',
            timestamp: '2026-01-01T00:00:00+00:00',
            bodyHash: 'abc123',
            keyId: 'key-1',
        );

        self::assertSame(
            "POST\n/api/data\n2026-01-01T00:00:00+00:00\nabc123",
            $components->canonicalString(),
        );
    }

    #[Test]
    public function success_result(): void
    {
        $result = SignatureVerificationResult::success();

        self::assertTrue($result->valid);
        self::assertSame('', $result->reason);
    }

    #[Test]
    public function failure_result(): void
    {
        $result = SignatureVerificationResult::failure('bad sig');

        self::assertFalse($result->valid);
        self::assertSame('bad sig', $result->reason);
    }
}
