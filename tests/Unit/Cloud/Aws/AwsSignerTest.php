<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Aws;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\AwsSigner;

use function hash;
use function str_contains;

#[CoversClass(AwsSigner::class)]
final class AwsSignerTest extends TestCase
{
    #[Test]
    public function signProducesAuthorizationHeader(): void
    {
        $signer = new AwsSigner('AKID', 'SECRET', 'us-east-1', 's3');

        $headers = $signer->sign(
            'GET',
            '/test-key',
            '',
            ['Host' => 'bucket.s3.us-east-1.amazonaws.com'],
            hash('sha256', ''),
            1700000000,
        );

        self::assertArrayHasKey('Authorization', $headers);
        self::assertStringContainsString('AWS4-HMAC-SHA256', $headers['Authorization']);
        self::assertStringContainsString('Credential=AKID/', $headers['Authorization']);
        self::assertStringContainsString('s3/aws4_request', $headers['Authorization']);
    }

    #[Test]
    public function signIncludesAmzDateHeader(): void
    {
        $signer = new AwsSigner('AKID', 'SECRET', 'eu-west-1', 'sqs');

        $headers = $signer->sign(
            'POST',
            '/',
            '',
            ['Host' => 'sqs.eu-west-1.amazonaws.com'],
            hash('sha256', 'body'),
            1700000000,
        );

        self::assertArrayHasKey('x-amz-date', $headers);
        self::assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $headers['x-amz-date']);
    }

    #[Test]
    public function signIncludesContentSha256(): void
    {
        $signer = new AwsSigner('AKID', 'SECRET', 'us-east-1', 'ses');
        $payloadHash = hash('sha256', 'test-payload');

        $headers = $signer->sign(
            'POST',
            '/',
            '',
            ['Host' => 'email.us-east-1.amazonaws.com'],
            $payloadHash,
            1700000000,
        );

        self::assertArrayHasKey('x-amz-content-sha256', $headers);
        self::assertSame($payloadHash, $headers['x-amz-content-sha256']);
    }

    #[Test]
    public function differentServicesProduceDifferentSignatures(): void
    {
        $s3Signer = new AwsSigner('AKID', 'SECRET', 'us-east-1', 's3');
        $sqsSigner = new AwsSigner('AKID', 'SECRET', 'us-east-1', 'sqs');

        $s3Headers = $s3Signer->sign(
            'GET',
            '/',
            '',
            ['Host' => 'test.amazonaws.com'],
            hash('sha256', ''),
            1700000000,
        );

        $sqsHeaders = $sqsSigner->sign(
            'GET',
            '/',
            '',
            ['Host' => 'test.amazonaws.com'],
            hash('sha256', ''),
            1700000000,
        );

        self::assertNotSame($s3Headers['Authorization'], $sqsHeaders['Authorization']);
    }

    #[Test]
    public function presignUrlGeneratesValidUrl(): void
    {
        $signer = new AwsSigner('AKID', 'SECRET', 'us-east-1', 's3');

        $url = $signer->presignUrl(
            'GET',
            'bucket.s3.us-east-1.amazonaws.com',
            '/test-key',
            3600,
            1700000000,
        );

        self::assertStringStartsWith('https://', $url);
        self::assertTrue(str_contains($url, 'X-Amz-Algorithm=AWS4-HMAC-SHA256'));
        self::assertTrue(str_contains($url, 'X-Amz-Credential=AKID'));
        self::assertTrue(str_contains($url, 'X-Amz-Expires=3600'));
        self::assertTrue(str_contains($url, 'X-Amz-Signature='));
    }

    #[Test]
    public function debugInfoRedactsKeys(): void
    {
        $signer = new AwsSigner('AKID', 'SECRET', 'us-east-1', 's3');

        $debug = $signer->__debugInfo();

        self::assertSame('[REDACTED]', $debug['accessKey']);
        self::assertSame('[REDACTED]', $debug['secretKey']);
        self::assertSame('us-east-1', $debug['region']);
        self::assertSame('s3', $debug['service']);
    }
}
