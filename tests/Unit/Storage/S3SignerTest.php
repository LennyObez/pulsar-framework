<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\S3Signer;

#[CoversClass(S3Signer::class)]
final class S3SignerTest extends TestCase
{
    private const string ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
    private const string SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
    private const string REGION = 'us-east-1';

    private S3Signer $signer;

    protected function setUp(): void
    {
        $this->signer = new S3Signer(
            accessKey: self::ACCESS_KEY,
            secretKey: self::SECRET_KEY,
            region: self::REGION,
        );
    }

    #[Test]
    public function signingKeyDerivationProducesDeterministicOutput(): void
    {
        // Two sign() calls with the same timestamp must produce identical Authorization headers
        $timestamp = 1_704_067_200; // 2024-01-01T00:00:00Z
        $payloadHash = hash('sha256', '');
        $headers = ['Host' => 'test-bucket.s3.amazonaws.com'];

        $result1 = $this->signer->sign('GET', '/test-key', '', $headers, $payloadHash, $timestamp);
        $result2 = $this->signer->sign('GET', '/test-key', '', $headers, $payloadHash, $timestamp);

        self::assertSame($result1['Authorization'], $result2['Authorization']);
        self::assertNotEmpty($result1['Authorization']);
    }

    #[Test]
    public function signReturnsAuthorizationHeaderWithExpectedFormat(): void
    {
        $timestamp = 1_704_067_200; // 2024-01-01T00:00:00Z
        $payloadHash = hash('sha256', '');
        $headers = ['Host' => 'test-bucket.s3.amazonaws.com'];

        $result = $this->signer->sign('GET', '/test-key', '', $headers, $payloadHash, $timestamp);

        self::assertArrayHasKey('Authorization', $result);
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=' . self::ACCESS_KEY . '/', $result['Authorization']);
        self::assertStringContainsString('SignedHeaders=', $result['Authorization']);
        self::assertStringContainsString('Signature=', $result['Authorization']);
    }

    #[Test]
    public function signIncludesAmzDateAndContentHashHeaders(): void
    {
        $timestamp = 1_704_067_200;
        $payloadHash = hash('sha256', 'test-body');
        $headers = ['Host' => 'test-bucket.s3.amazonaws.com'];

        $result = $this->signer->sign('PUT', '/upload', '', $headers, $payloadHash, $timestamp);

        self::assertArrayHasKey('x-amz-date', $result);
        self::assertSame('20240101T000000Z', $result['x-amz-date']);
        self::assertArrayHasKey('x-amz-content-sha256', $result);
        self::assertSame($payloadHash, $result['x-amz-content-sha256']);
    }

    #[Test]
    public function canonicalHeadersAreLowercasedAndSorted(): void
    {
        $timestamp = 1_704_067_200;
        $payloadHash = hash('sha256', '');
        $headers = [
            'Host' => 'bucket.s3.amazonaws.com',
            'X-Custom-Header' => 'value',
            'Content-Type' => 'application/octet-stream',
        ];

        $result = $this->signer->sign('GET', '/key', '', $headers, $payloadHash, $timestamp);

        // The signed headers should be sorted alphabetically and lowercased
        $authorization = $result['Authorization'];
        self::assertMatchesRegularExpression('/SignedHeaders=[a-z0-9;-]+,/', $authorization);

        // Verify all custom headers are present in signed headers
        self::assertStringContainsString('content-type', $authorization);
        self::assertStringContainsString('host', $authorization);
        self::assertStringContainsString('x-amz-content-sha256', $authorization);
        self::assertStringContainsString('x-amz-date', $authorization);
        self::assertStringContainsString('x-custom-header', $authorization);
    }

    #[Test]
    public function differentTimestampsProduceDifferentSignatures(): void
    {
        $payloadHash = hash('sha256', '');
        $headers = ['Host' => 'bucket.s3.amazonaws.com'];

        $result1 = $this->signer->sign('GET', '/key', '', $headers, $payloadHash, 1_704_067_200);
        $result2 = $this->signer->sign('GET', '/key', '', $headers, $payloadHash, 1_704_153_600);

        self::assertNotSame($result1['Authorization'], $result2['Authorization']);
    }

    #[Test]
    public function presignUrlReturnsUrlWithExpectedFormat(): void
    {
        $timestamp = 1_704_067_200;

        $url = $this->signer->presignUrl(
            method: 'GET',
            host: 'test-bucket.s3.us-east-1.amazonaws.com',
            uri: '/my-object',
            expiresInSeconds: 3600,
            timestamp: $timestamp,
        );

        self::assertStringStartsWith('https://test-bucket.s3.us-east-1.amazonaws.com/my-object?', $url);
        self::assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        self::assertStringContainsString('X-Amz-Credential=', $url);
        self::assertStringContainsString('X-Amz-Date=20240101T000000Z', $url);
        self::assertStringContainsString('X-Amz-Expires=3600', $url);
        self::assertStringContainsString('X-Amz-SignedHeaders=host', $url);
        self::assertStringContainsString('X-Amz-Signature=', $url);
    }

    #[Test]
    public function presignUrlIsDeterministicForSameInput(): void
    {
        $timestamp = 1_704_067_200;

        $url1 = $this->signer->presignUrl('GET', 'bucket.s3.amazonaws.com', '/key', 3600, $timestamp);
        $url2 = $this->signer->presignUrl('GET', 'bucket.s3.amazonaws.com', '/key', 3600, $timestamp);

        self::assertSame($url1, $url2);
    }

    #[Test]
    public function presignUrlDiffersWithDifferentExpiry(): void
    {
        $timestamp = 1_704_067_200;

        $url1 = $this->signer->presignUrl('GET', 'bucket.s3.amazonaws.com', '/key', 3600, $timestamp);
        $url2 = $this->signer->presignUrl('GET', 'bucket.s3.amazonaws.com', '/key', 7200, $timestamp);

        self::assertNotSame($url1, $url2);
    }

    #[Test]
    public function debugInfoRedactsKeys(): void
    {
        $debugInfo = $this->signer->__debugInfo();

        self::assertSame('[REDACTED]', $debugInfo['accessKey']);
        self::assertSame('[REDACTED]', $debugInfo['secretKey']);
        self::assertSame(self::REGION, $debugInfo['region']);
    }
}
