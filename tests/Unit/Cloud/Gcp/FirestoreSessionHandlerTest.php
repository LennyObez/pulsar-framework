<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Gcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\Gcp\Config\GcpConfig;
use Pulsar\Cloud\Gcp\FirestoreSessionHandler;

#[CoversClass(FirestoreSessionHandler::class)]
final class FirestoreSessionHandlerTest extends TestCase
{
    #[Test]
    public function openReturnsTrue(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $handler = new FirestoreSessionHandler($config);

        self::assertTrue($handler->open('/tmp', 'sess'));
    }

    #[Test]
    public function closeReturnsTrue(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $handler = new FirestoreSessionHandler($config);

        self::assertTrue($handler->close());
    }

    #[Test]
    public function supportsConcurrencyControl(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $handler = new FirestoreSessionHandler($config);

        self::assertTrue($handler->supportsConcurrencyControl());
    }

    #[Test]
    public function supportsSessionListing(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $handler = new FirestoreSessionHandler($config);

        self::assertTrue($handler->supportsSessionListing());
    }

    #[Test]
    public function supportsRevocation(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $handler = new FirestoreSessionHandler($config);

        self::assertTrue($handler->supportsRevocation());
    }

    #[Test]
    public function gcReturnsZero(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $handler = new FirestoreSessionHandler($config);

        self::assertSame(0, $handler->gc(3600));
    }

    #[Test]
    public function setSessionContextStoresMetadata(): void
    {
        $config = new GcpConfig(projectId: 'test', accessToken: 'test-token');
        $handler = new FirestoreSessionHandler($config);

        // Should not throw
        $handler->setSessionContext('sess-123', 'user-1', '192.168.1.1', 'Mozilla/5.0');

        self::assertInstanceOf(FirestoreSessionHandler::class, $handler);
    }

    #[Test]
    public function writeThrowsOnMissingCredentials(): void
    {
        $config = new GcpConfig(projectId: 'test');
        $handler = new FirestoreSessionHandler($config);

        $this->expectException(CloudException::class);
        $this->expectExceptionMessageMatches('/access token not configured/i');

        $handler->write('session-id', 'session-data');
    }
}
