<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Azure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Azure\BlobStorageAdapter;
use Pulsar\Cloud\Azure\Config\AzureConfig;
use Pulsar\Storage\StorageException;
use ReflectionMethod;

#[CoversClass(BlobStorageAdapter::class)]
final class BlobStorageAdapterTest extends TestCase
{
    #[Test]
    public function buildBlobNameWithPrefix(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $adapter = new BlobStorageAdapter($config, 'myaccount', 'container', 'uploads');

        $name = $this->callPrivateMethod($adapter, 'buildBlobName', 'photo.jpg');

        self::assertSame('uploads/photo.jpg', $name);
    }

    #[Test]
    public function buildBlobNameWithoutPrefix(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $adapter = new BlobStorageAdapter($config, 'myaccount', 'container');

        $name = $this->callPrivateMethod($adapter, 'buildBlobName', 'photo.jpg');

        self::assertSame('photo.jpg', $name);
    }

    #[Test]
    public function buildBlobNameStripsLeadingSlash(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $adapter = new BlobStorageAdapter($config, 'myaccount', 'container');

        $name = $this->callPrivateMethod($adapter, 'buildBlobName', '/photo.jpg');

        self::assertSame('photo.jpg', $name);
    }

    #[Test]
    public function blobUrlUsesAccountAndContainer(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $adapter = new BlobStorageAdapter($config, 'myaccount', 'images');

        /** @var string $url */
        $url = $this->callPrivateMethod($adapter, 'blobUrl', 'photo.jpg');

        self::assertStringContainsString('myaccount.blob.core.windows.net', $url);
        self::assertStringContainsString('/images/', $url);
        self::assertStringContainsString('photo.jpg', $url);
    }

    #[Test]
    public function blobUrlUsesCustomEndpoint(): void
    {
        $config = new AzureConfig(accessToken: 'test-token', endpoint: 'http://127.0.0.1:10000/devstoreaccount1');
        $adapter = new BlobStorageAdapter($config, 'devstoreaccount1', 'test');

        /** @var string $url */
        $url = $this->callPrivateMethod($adapter, 'blobUrl', 'file.txt');

        self::assertStringStartsWith('http://127.0.0.1:10000/devstoreaccount1/', $url);
    }

    #[Test]
    public function temporaryUrlReturnsNull(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $adapter = new BlobStorageAdapter($config, 'myaccount', 'container');

        self::assertNull($adapter->temporaryUrl('test.txt'));
    }

    #[Test]
    public function missingCredentialsThrowsOnPut(): void
    {
        $config = new AzureConfig();
        $adapter = new BlobStorageAdapter($config, 'myaccount', 'container');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/credentials not configured/i');

        $adapter->put('key', 'content');
    }

    #[Test]
    public function parseListResponseWithValidXml(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $adapter = new BlobStorageAdapter($config, 'myaccount', 'container');

        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <EnumerationResults>
              <Blobs>
                <Blob>
                  <Name>photo.jpg</Name>
                  <Properties>
                    <Content-Length>1234</Content-Length>
                    <Last-Modified>Mon, 01 Jan 2024 00:00:00 GMT</Last-Modified>
                    <Content-Type>image/jpeg</Content-Type>
                  </Properties>
                </Blob>
                <Blob>
                  <Name>doc.pdf</Name>
                  <Properties>
                    <Content-Length>5678</Content-Length>
                    <Last-Modified>Sat, 15 Jun 2024 12:30:00 GMT</Last-Modified>
                    <Content-Type>application/pdf</Content-Type>
                  </Properties>
                </Blob>
              </Blobs>
            </EnumerationResults>
            XML;

        /** @var list<\Pulsar\Storage\StorageObject> $objects */
        $objects = $this->callPrivateMethod($adapter, 'parseListResponse', $xml);

        self::assertCount(2, $objects);
        self::assertSame('photo.jpg', $objects[0]->key);
        self::assertSame(1234, $objects[0]->size);
        self::assertSame('image/jpeg', $objects[0]->contentType);
        self::assertSame('doc.pdf', $objects[1]->key);
        self::assertSame(5678, $objects[1]->size);
    }

    #[Test]
    public function parseListResponseWithInvalidXml(): void
    {
        $config = new AzureConfig(accessToken: 'test-token');
        $adapter = new BlobStorageAdapter($config, 'myaccount', 'container');

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
