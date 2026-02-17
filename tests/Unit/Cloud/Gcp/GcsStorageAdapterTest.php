<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Gcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Gcp\Config\GcpConfig;
use Pulsar\Cloud\Gcp\GcsStorageAdapter;
use Pulsar\Storage\StorageException;
use ReflectionMethod;

#[CoversClass(GcsStorageAdapter::class)]
final class GcsStorageAdapterTest extends TestCase
{
    #[Test]
    public function buildObjectKeyWithPrefix(): void
    {
        $config = new GcpConfig(accessToken: 'test-token');
        $adapter = new GcsStorageAdapter($config, 'my-bucket', 'uploads');

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', 'photo.jpg');

        self::assertSame('uploads/photo.jpg', $key);
    }

    #[Test]
    public function buildObjectKeyWithoutPrefix(): void
    {
        $config = new GcpConfig(accessToken: 'test-token');
        $adapter = new GcsStorageAdapter($config, 'my-bucket');

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', 'photo.jpg');

        self::assertSame('photo.jpg', $key);
    }

    #[Test]
    public function buildObjectKeyStripsLeadingSlash(): void
    {
        $config = new GcpConfig(accessToken: 'test-token');
        $adapter = new GcsStorageAdapter($config, 'my-bucket');

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', '/photo.jpg');

        self::assertSame('photo.jpg', $key);
    }

    #[Test]
    public function temporaryUrlReturnsNull(): void
    {
        $config = new GcpConfig(accessToken: 'test-token');
        $adapter = new GcsStorageAdapter($config, 'my-bucket');

        self::assertNull($adapter->temporaryUrl('test.txt'));
    }

    #[Test]
    public function missingCredentialsThrowsOnPut(): void
    {
        $config = new GcpConfig();
        $adapter = new GcsStorageAdapter($config, 'my-bucket');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/credentials not configured/i');

        $adapter->put('key', 'content');
    }

    #[Test]
    public function parseListResponseWithValidJson(): void
    {
        $config = new GcpConfig(accessToken: 'test-token');
        $adapter = new GcsStorageAdapter($config, 'my-bucket');

        $json = '{"items":[{"name":"photo.jpg","size":"1234","updated":"2024-01-01T00:00:00Z"},{"name":"doc.pdf","size":"5678","updated":"2024-06-15T12:30:00Z","contentType":"application/pdf"}]}';

        /** @var list<\Pulsar\Storage\StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', $json);

        self::assertCount(2, $objects);
        self::assertSame('photo.jpg', $objects[0]->key);
        self::assertSame(1234, $objects[0]->size);
        self::assertSame('doc.pdf', $objects[1]->key);
        self::assertSame(5678, $objects[1]->size);
        self::assertSame('application/pdf', $objects[1]->contentType);
    }

    #[Test]
    public function parseListResponseWithEmptyItems(): void
    {
        $config = new GcpConfig(accessToken: 'test-token');
        $adapter = new GcsStorageAdapter($config, 'my-bucket');

        /** @var list<\Pulsar\Storage\StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', '{}');

        self::assertSame([], $objects);
    }

    private function callPrivateMethod(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);

        return $ref->invoke($obj, ...$args);
    }
}
