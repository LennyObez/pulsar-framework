<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Aws;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\Aws\S3StorageAdapter;
use Pulsar\Storage\StorageException;
use ReflectionMethod;

#[CoversClass(S3StorageAdapter::class)]
final class S3StorageAdapterTest extends TestCase
{
    #[Test]
    public function virtualHostedStyleDefaultEndpoint(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $adapter = new S3StorageAdapter($config, 'my-bucket');

        $host = $this->callPrivateMethod($adapter, 'getHost');

        self::assertSame('my-bucket.s3.us-east-1.amazonaws.com', $host);
    }

    #[Test]
    public function pathStyleDefaultEndpoint(): void
    {
        $config = new AwsConfig(region: 'eu-west-1', accessKey: 'AKID', secretKey: 'SECRET');
        $adapter = new S3StorageAdapter($config, 'my-bucket', usePathStyle: true);

        $host = $this->callPrivateMethod($adapter, 'getHost');

        self::assertSame('s3.eu-west-1.amazonaws.com', $host);
    }

    #[Test]
    public function virtualHostedStyleCustomEndpoint(): void
    {
        $config = new AwsConfig(region: 'auto', accessKey: 'AKID', secretKey: 'SECRET', endpoint: 'https://minio.local:9000');
        $adapter = new S3StorageAdapter($config, 'test-bucket');

        $host = $this->callPrivateMethod($adapter, 'getHost');

        self::assertSame('test-bucket.minio.local:9000', $host);
    }

    #[Test]
    public function pathStyleCustomEndpoint(): void
    {
        $config = new AwsConfig(region: 'auto', accessKey: 'AKID', secretKey: 'SECRET', endpoint: 'https://minio.local:9000');
        $adapter = new S3StorageAdapter($config, 'test-bucket', usePathStyle: true);

        $host = $this->callPrivateMethod($adapter, 'getHost');

        self::assertSame('minio.local:9000', $host);
    }

    #[Test]
    public function buildObjectKeyWithPrefix(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $adapter = new S3StorageAdapter($config, 'bucket', prefix: 'uploads');

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', 'photo.jpg');

        self::assertSame('/uploads/photo.jpg', $key);
    }

    #[Test]
    public function buildObjectKeyWithoutPrefix(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $adapter = new S3StorageAdapter($config, 'bucket');

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', 'photo.jpg');

        self::assertSame('/photo.jpg', $key);
    }

    #[Test]
    public function buildObjectKeyStripsLeadingSlash(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $adapter = new S3StorageAdapter($config, 'bucket');

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', '/photo.jpg');

        self::assertSame('/photo.jpg', $key);
    }

    #[Test]
    public function temporaryUrlReturnsPresignedUrl(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $adapter = new S3StorageAdapter($config, 'my-bucket');

        $url = $adapter->temporaryUrl('test.txt', 3600);

        self::assertNotNull($url);
        self::assertStringStartsWith('https://', $url);
        self::assertStringContainsString('X-Amz-Signature=', $url);
    }

    #[Test]
    public function missingCredentialsThrowsOnPut(): void
    {
        $config = new AwsConfig(region: 'us-east-1');
        $adapter = new S3StorageAdapter($config, 'bucket');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/credentials not configured/i');

        $adapter->put('key', 'content');
    }

    #[Test]
    public function parseListResponseWithValidXml(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $adapter = new S3StorageAdapter($config, 'bucket');

        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
              <Contents>
                <Key>photo.jpg</Key>
                <Size>1234</Size>
                <LastModified>2024-01-01T00:00:00.000Z</LastModified>
              </Contents>
              <Contents>
                <Key>doc.pdf</Key>
                <Size>5678</Size>
                <LastModified>2024-06-15T12:30:00.000Z</LastModified>
              </Contents>
            </ListBucketResult>
            XML;

        /** @var list<\Pulsar\Storage\StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', $xml);

        self::assertCount(2, $objects);
        self::assertSame('photo.jpg', $objects[0]->key);
        self::assertSame(1234, $objects[0]->size);
        self::assertSame('doc.pdf', $objects[1]->key);
        self::assertSame(5678, $objects[1]->size);
    }

    #[Test]
    public function parseListResponseWithInvalidXml(): void
    {
        $config = new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET');
        $adapter = new S3StorageAdapter($config, 'bucket');

        /** @var list<\Pulsar\Storage\StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', 'not xml');

        self::assertSame([], $objects);
    }

    private function callPrivateMethod(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($obj, $method);

        return $ref->invoke($obj, ...$args);
    }
}
