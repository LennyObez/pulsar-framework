<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Gcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Gcp\Config\GcpConfig;

#[CoversClass(GcpConfig::class)]
final class GcpConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new GcpConfig();

        self::assertSame('', $config->projectId);
        self::assertSame('', $config->credentialsPath);
        self::assertNull($config->endpoint);
        self::assertNull($config->accessToken);
    }

    #[Test]
    public function fromArrayWithAllFields(): void
    {
        $config = GcpConfig::fromArray([
            'project_id' => 'my-project-123',
            'credentials_path' => '/path/to/key.json',
            'endpoint' => 'http://localhost:8085',
            'access_token' => 'test-gcp-token-placeholder',
        ]);

        self::assertSame('my-project-123', $config->projectId);
        self::assertSame('/path/to/key.json', $config->credentialsPath);
        self::assertSame('http://localhost:8085', $config->endpoint);
        self::assertSame('test-gcp-token-placeholder', $config->accessToken);
    }

    #[Test]
    public function fromArrayHandlesInvalidTypes(): void
    {
        $config = GcpConfig::fromArray([
            'project_id' => 42,
            'credentials_path' => false,
        ]);

        self::assertSame('', $config->projectId);
        self::assertSame('', $config->credentialsPath);
    }

    #[Test]
    public function resolveProjectIdFromConfig(): void
    {
        $config = new GcpConfig(projectId: 'configured-project');

        self::assertSame('configured-project', $config->resolveProjectId());
    }

    #[Test]
    public function resolveAccessTokenFromConfig(): void
    {
        $config = new GcpConfig(accessToken: 'test-gcp-token-placeholder');

        self::assertSame('test-gcp-token-placeholder', $config->resolveAccessToken());
    }

    #[Test]
    public function resolveAccessTokenReturnsEmptyWhenNotSet(): void
    {
        $config = new GcpConfig();

        // Without env vars, should return empty string
        $token = $config->resolveAccessToken();

        self::assertSame('', $token);
    }

    #[Test]
    public function loadCredentialsReturnsEmptyForMissingFile(): void
    {
        $config = new GcpConfig(credentialsPath: '/nonexistent/path.json');

        $creds = $config->loadCredentials();

        self::assertSame([], $creds);
    }

    #[Test]
    public function debugInfoRedactsToken(): void
    {
        $config = new GcpConfig(
            projectId: 'my-project',
            accessToken: 'test-gcp-secret-token',
        );

        $debug = $config->__debugInfo();

        self::assertSame('my-project', $debug['projectId']);
        self::assertSame('[REDACTED]', $debug['accessToken']);
    }

    #[Test]
    public function debugInfoShowsNullTokenAsNull(): void
    {
        $config = new GcpConfig();

        $debug = $config->__debugInfo();

        self::assertNull($debug['accessToken']);
    }
}
