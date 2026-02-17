<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Azure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Azure\Config\AzureConfig;

#[CoversClass(AzureConfig::class)]
final class AzureConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new AzureConfig();

        self::assertSame('', $config->tenantId);
        self::assertSame('', $config->clientId);
        self::assertSame('', $config->clientSecret);
        self::assertNull($config->endpoint);
        self::assertNull($config->accessToken);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = AzureConfig::fromArray([
            'tenant_id' => 'tenant-abc',
            'client_id' => 'client-xyz',
            'client_secret' => 'secret-123',
            'endpoint' => 'http://localhost:10000',
            'access_token' => 'eyJ0eXAiOi...',
        ]);

        self::assertSame('tenant-abc', $config->tenantId);
        self::assertSame('client-xyz', $config->clientId);
        self::assertSame('secret-123', $config->clientSecret);
        self::assertSame('http://localhost:10000', $config->endpoint);
        self::assertSame('eyJ0eXAiOi...', $config->accessToken);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $config = AzureConfig::fromArray([
            'tenant_id' => 42,
            'client_id' => false,
            'client_secret' => [],
        ]);

        self::assertSame('', $config->tenantId);
        self::assertSame('', $config->clientId);
        self::assertSame('', $config->clientSecret);
    }

    #[Test]
    public function resolveAccessTokenFromConfig(): void
    {
        $config = new AzureConfig(accessToken: 'test-azure-token');

        self::assertSame('test-azure-token', $config->resolveAccessToken());
    }

    #[Test]
    public function resolveAccessTokenReturnsEmptyWhenNotSet(): void
    {
        $config = new AzureConfig();

        $token = $config->resolveAccessToken();

        // Without env vars, should return empty string
        self::assertSame('', $token);
    }

    #[Test]
    public function resolveTenantIdFromConfig(): void
    {
        $config = new AzureConfig(tenantId: 'my-tenant');

        self::assertSame('my-tenant', $config->resolveTenantId());
    }

    #[Test]
    public function debugInfoRedactsSecrets(): void
    {
        $config = new AzureConfig(
            tenantId: 'tenant-abc',
            clientId: 'client-xyz',
            clientSecret: 'super-secret',
            accessToken: 'token-value',
        );

        $debug = $config->__debugInfo();

        self::assertSame('tenant-abc', $debug['tenantId']);
        self::assertSame('client-xyz', $debug['clientId']);
        self::assertSame('[REDACTED]', $debug['clientSecret']);
        self::assertSame('[REDACTED]', $debug['accessToken']);
    }

    #[Test]
    public function debugInfoShowsNullTokenAsNull(): void
    {
        $config = new AzureConfig();

        $debug = $config->__debugInfo();

        self::assertNull($debug['accessToken']);
    }
}
