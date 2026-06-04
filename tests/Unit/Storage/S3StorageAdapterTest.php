<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Storage\S3StorageAdapter;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageObject;
use ReflectionMethod;

use function assert;
use function is_string;

#[CoversClass(S3StorageAdapter::class)]
final class S3StorageAdapterTest extends TestCase
{
    // --- Host building (getHost) ---

    #[Test]
    public function virtualHostedStyleDefaultEndpoint(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'my-bucket',
        );

        $host = $this->callPrivateMethod($adapter, 'getHost');

        self::assertSame('my-bucket.s3.us-east-1.amazonaws.com', $host);
    }

    #[Test]
    public function pathStyleDefaultEndpoint(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'eu-west-1',
            bucket: 'my-bucket',
            usePathStyle: true,
        );

        $host = $this->callPrivateMethod($adapter, 'getHost');

        self::assertSame('s3.eu-west-1.amazonaws.com', $host);
    }

    #[Test]
    public function virtualHostedStyleCustomEndpoint(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'auto',
            bucket: 'test-bucket',
            endpoint: 'https://minio.local:9000',
        );

        $host = $this->callPrivateMethod($adapter, 'getHost');

        self::assertSame('test-bucket.minio.local:9000', $host);
    }

    #[Test]
    public function pathStyleCustomEndpoint(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'auto',
            bucket: 'test-bucket',
            endpoint: 'https://minio.local:9000',
            usePathStyle: true,
        );

        $host = $this->callPrivateMethod($adapter, 'getHost');

        self::assertSame('minio.local:9000', $host);
    }

    #[Test]
    public function customEndpointStripsHttpProtocol(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'auto',
            bucket: 'b',
            endpoint: 'http://localhost:9000',
            usePathStyle: true,
        );

        $host = $this->callPrivateMethod($adapter, 'getHost');

        self::assertSame('localhost:9000', $host);
    }

    // --- Object key building (buildObjectKey) ---

    #[Test]
    public function buildObjectKeyWithoutPrefix(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
        );

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', 'file.txt');

        self::assertSame('/file.txt', $key);
    }

    #[Test]
    public function buildObjectKeyWithPrefix(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
            prefix: 'uploads/2024',
        );

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', 'file.txt');

        self::assertSame('/uploads/2024/file.txt', $key);
    }

    #[Test]
    public function buildObjectKeyStripsLeadingSlashFromInput(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
        );

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', '/file.txt');

        self::assertSame('/file.txt', $key);
    }

    #[Test]
    public function buildObjectKeyNormalizesTrailingSlashOnPrefix(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
            prefix: 'uploads/',
        );

        $key = $this->callPrivateMethod($adapter, 'buildObjectKey', 'file.txt');

        self::assertSame('/uploads/file.txt', $key);
    }

    // --- XML list response parsing (parseListResponse) ---

    #[Test]
    public function parseListResponseWithNamespacedXml(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
        );

        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
              <Contents>
                <Key>docs/readme.md</Key>
                <Size>1024</Size>
                <LastModified>2024-06-15T10:00:00.000Z</LastModified>
              </Contents>
              <Contents>
                <Key>docs/guide.md</Key>
                <Size>2048</Size>
                <LastModified>2024-06-14T10:00:00.000Z</LastModified>
              </Contents>
            </ListBucketResult>
            XML;

        /** @var list<StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', $xml);

        self::assertCount(2, $objects);
        self::assertSame('docs/readme.md', $objects[0]->key);
        self::assertSame(1024, $objects[0]->size);
        self::assertSame('docs/guide.md', $objects[1]->key);
        self::assertSame(2048, $objects[1]->size);
    }

    #[Test]
    public function parseListResponseWithoutNamespace(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
        );

        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ListBucketResult>
              <Contents>
                <Key>test.txt</Key>
                <Size>512</Size>
                <LastModified>2024-01-01T00:00:00.000Z</LastModified>
              </Contents>
            </ListBucketResult>
            XML;

        /** @var list<StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', $xml);

        self::assertCount(1, $objects);
        self::assertSame('test.txt', $objects[0]->key);
        self::assertSame(512, $objects[0]->size);
    }

    #[Test]
    public function parseListResponseStripsPrefixFromKeys(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
            prefix: 'uploads',
        );

        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
              <Contents>
                <Key>uploads/photo.jpg</Key>
                <Size>99999</Size>
                <LastModified>2024-06-15T10:00:00.000Z</LastModified>
              </Contents>
            </ListBucketResult>
            XML;

        /** @var list<StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', $xml);

        self::assertCount(1, $objects);
        self::assertSame('photo.jpg', $objects[0]->key);
    }

    #[Test]
    public function parseListResponseReturnsEmptyForInvalidXml(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
        );

        /** @var list<StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', 'not xml');

        self::assertSame([], $objects);
    }

    #[Test]
    public function parseListResponseReturnsEmptyForEmptyBucket(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
        );

        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            </ListBucketResult>
            XML;

        /** @var list<StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', $xml);

        self::assertSame([], $objects);
    }

    // --- Credential resolution (getSigner) ---

    #[Test]
    public function getSignerThrowsWithoutCredentials(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
        );

        // Ensure no env vars are set for this test
        $prevAccess = getenv('AWS_ACCESS_KEY_ID');
        $prevSecret = getenv('AWS_SECRET_ACCESS_KEY');
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');

        try {
            $this->expectException(StorageException::class);
            $this->expectExceptionMessage('credentials not configured');

            $this->callPrivateMethod($adapter, 'getSigner');
        } finally {
            if ($prevAccess !== false) {
                putenv('AWS_ACCESS_KEY_ID=' . $prevAccess);
            }
            if ($prevSecret !== false) {
                putenv('AWS_SECRET_ACCESS_KEY=' . $prevSecret);
            }
        }
    }

    #[Test]
    public function getSignerUsesConstructorCredentials(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
            accessKey: 'AKIAIOSFODNN7EXAMPLE',
            secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        );

        // Should not throw — credentials are provided via constructor
        $signer = $this->callPrivateMethod($adapter, 'getSigner');

        self::assertNotNull($signer);
    }

    #[Test]
    public function getSignerCachesInstance(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
            accessKey: 'AKIAIOSFODNN7EXAMPLE',
            secretKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        );

        $signer1 = $this->callPrivateMethod($adapter, 'getSigner');
        $signer2 = $this->callPrivateMethod($adapter, 'getSigner');

        self::assertSame($signer1, $signer2);
    }

    // --- Different region configurations ---

    #[Test]
    #[DataProvider('regionProvider')]
    public function hostContainsRegion(string $region): void
    {
        $adapter = new S3StorageAdapter(
            region: $region,
            bucket: 'test',
        );

        $host = $this->callPrivateMethod($adapter, 'getHost');
        assert(is_string($host));

        self::assertStringContainsString($region, $host);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function regionProvider(): iterable
    {
        yield 'us-east-1' => ['us-east-1'];
        yield 'eu-west-1' => ['eu-west-1'];
        yield 'ap-southeast-1' => ['ap-southeast-1'];
        yield 'us-gov-west-1' => ['us-gov-west-1'];
    }

    // --- Header injection prevention (sanitizeHeaderValue) ---

    #[Test]
    public function sanitizeHeaderValueStripsCrlf(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
        );

        $malicious = "image/jpeg\r\nX-Injected: evil";
        $clean = $this->callPrivateMethod($adapter, 'sanitizeHeaderValue', $malicious);

        self::assertSame('image/jpegX-Injected: evil', $clean);
        self::assertStringNotContainsString("\r", $clean);
        self::assertStringNotContainsString("\n", $clean);
    }

    #[Test]
    public function sanitizeHeaderValueLeavesCleanValueUnchanged(): void
    {
        $adapter = new S3StorageAdapter(
            region: 'us-east-1',
            bucket: 'bucket',
        );

        $value = 'text/plain; charset=utf-8';
        $clean = $this->callPrivateMethod($adapter, 'sanitizeHeaderValue', $value);

        self::assertSame($value, $clean);
    }

    private function callPrivateMethod(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($object, $method);

        return $ref->invoke($object, ...$args);
    }
}
