<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Aws;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\Config\AwsConfig;

#[CoversClass(AwsConfig::class)]
final class AwsConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new AwsConfig();

        self::assertSame('us-east-1', $config->region);
        self::assertSame('', $config->accessKey);
        self::assertSame('', $config->secretKey);
        self::assertNull($config->endpoint);
        self::assertNull($config->sessionToken);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = AwsConfig::fromArray([
            'region' => 'eu-west-1',
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'endpoint' => 'http://localhost:4566',
            'session_token' => 'FwoGZXIvYXdzEBY...',
        ]);

        self::assertSame('eu-west-1', $config->region);
        self::assertSame('AKIAIOSFODNN7EXAMPLE', $config->accessKey);
        self::assertSame('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', $config->secretKey);
        self::assertSame('http://localhost:4566', $config->endpoint);
        self::assertSame('FwoGZXIvYXdzEBY...', $config->sessionToken);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $config = AwsConfig::fromArray([
            'region' => 42,
            'access_key' => false,
            'secret_key' => [],
        ]);

        self::assertSame('us-east-1', $config->region);
        self::assertSame('', $config->accessKey);
        self::assertSame('', $config->secretKey);
    }

    #[Test]
    public function resolveCredentialsFromConfig(): void
    {
        $config = new AwsConfig(
            accessKey: 'AKID',
            secretKey: 'SECRET',
        );

        $creds = $config->resolveCredentials();

        self::assertSame('AKID', $creds['access_key']);
        self::assertSame('SECRET', $creds['secret_key']);
    }

    #[Test]
    public function debugInfoRedactsSecrets(): void
    {
        $config = new AwsConfig(
            region: 'us-west-2',
            accessKey: 'AKIAIOSFODNN7EXAMPLE',
            secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            sessionToken: 'FwoGZXIvYXdzEBY...',
        );

        $debug = $config->__debugInfo();

        self::assertSame('us-west-2', $debug['region']);
        self::assertSame('[REDACTED]', $debug['accessKey']);
        self::assertSame('[REDACTED]', $debug['secretKey']);
        self::assertSame('[REDACTED]', $debug['sessionToken']);
    }

    #[Test]
    public function debugInfoShowsNullTokenAsNull(): void
    {
        $config = new AwsConfig();

        $debug = $config->__debugInfo();

        self::assertNull($debug['sessionToken']);
    }
}
