<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\CloudException;
use RuntimeException;

#[CoversClass(CloudException::class)]
final class CloudExceptionTest extends TestCase
{
    #[Test]
    public function providerNotConfigured(): void
    {
        $e = CloudException::providerNotConfigured('aws');

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertStringContainsString('aws', $e->getMessage());
        self::assertStringContainsString('not configured', $e->getMessage());
    }

    #[Test]
    public function authenticationFailed(): void
    {
        $e = CloudException::authenticationFailed('gcp', 'invalid token');

        self::assertStringContainsString('gcp', $e->getMessage());
        self::assertStringContainsString('invalid token', $e->getMessage());
    }

    #[Test]
    public function requestFailed(): void
    {
        $previous = new RuntimeException('network error');
        $e = CloudException::requestFailed('s3', 'timeout', $previous);

        self::assertStringContainsString('s3', $e->getMessage());
        self::assertStringContainsString('timeout', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    #[Test]
    public function secretNotFound(): void
    {
        $e = CloudException::secretNotFound('Azure Key Vault', 'db-password');

        self::assertStringContainsString('db-password', $e->getMessage());
        self::assertStringContainsString('Azure Key Vault', $e->getMessage());
    }

    #[Test]
    public function connectionFailed(): void
    {
        $e = CloudException::connectionFailed('sqs', 'DNS resolution failed');

        self::assertStringContainsString('sqs', $e->getMessage());
        self::assertStringContainsString('DNS resolution failed', $e->getMessage());
    }
}
