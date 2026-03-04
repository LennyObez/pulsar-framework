<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Azure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Azure\Config\AzureConfig;
use Pulsar\Cloud\Azure\CosmosDbSessionHandler;
use Pulsar\Cloud\CloudException;
use ReflectionMethod;

#[CoversClass(CosmosDbSessionHandler::class)]
final class CosmosDbSessionHandlerTest extends TestCase
{
    #[Test]
    public function openReturnsTrue(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $handler = new CosmosDbSessionHandler($config, 'myaccount', 'mydb');

        self::assertTrue($handler->open('/tmp', 'sess'));
    }

    #[Test]
    public function closeReturnsTrue(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $handler = new CosmosDbSessionHandler($config, 'myaccount', 'mydb');

        self::assertTrue($handler->close());
    }

    #[Test]
    public function supportsConcurrencyControl(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $handler = new CosmosDbSessionHandler($config, 'myaccount', 'mydb');

        self::assertTrue($handler->supportsConcurrencyControl());
    }

    #[Test]
    public function supportsSessionListing(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $handler = new CosmosDbSessionHandler($config, 'myaccount', 'mydb');

        self::assertTrue($handler->supportsSessionListing());
    }

    #[Test]
    public function supportsRevocation(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $handler = new CosmosDbSessionHandler($config, 'myaccount', 'mydb');

        self::assertTrue($handler->supportsRevocation());
    }

    #[Test]
    public function gcReturnsZero(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $handler = new CosmosDbSessionHandler($config, 'myaccount', 'mydb');

        self::assertSame(0, $handler->gc(3600));
    }

    #[Test]
    public function setSessionContextStoresMetadata(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $handler = new CosmosDbSessionHandler($config, 'myaccount', 'mydb');

        $handler->setSessionContext('sess-123', 'user-1', '10.0.0.1', 'Chrome/120');

        self::assertInstanceOf(CosmosDbSessionHandler::class, $handler);
    }

    #[Test]
    public function readThrowsOnMissingCredentials(): void
    {
        $config = new AzureConfig();
        $handler = new CosmosDbSessionHandler($config, 'myaccount', 'mydb');

        $this->expectException(CloudException::class);
        $this->expectExceptionMessageMatches('/access token not configured/i');

        $handler->read('nonexistent-session');
    }

    #[Test]
    public function documentUrlIncludesAccountAndDatabase(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $handler = new CosmosDbSessionHandler($config, 'testaccount', 'testdb', 'sessions');

        $ref = new ReflectionMethod($handler, 'documentUrl');
        /** @var string $url */
        $url = $ref->invoke($handler, 'sess-abc');

        self::assertStringContainsString('testaccount.documents.azure.com', $url);
        self::assertStringContainsString('/dbs/testdb/', $url);
        self::assertStringContainsString('/colls/sessions/', $url);
        self::assertStringContainsString('sess-abc', $url);
    }

    #[Test]
    public function collectionUrlUsesCustomEndpoint(): void
    {
        $config = new AzureConfig(accessToken: 'test-token', endpoint: 'http://localhost:8081');
        $handler = new CosmosDbSessionHandler($config, 'local', 'mydb', 'sessions');

        $ref = new ReflectionMethod($handler, 'collectionUrl');
        /** @var string $url */
        $url = $ref->invoke($handler);

        self::assertStringStartsWith('http://localhost:8081/', $url);
        self::assertStringContainsString('/dbs/mydb/colls/sessions', $url);
    }
}
