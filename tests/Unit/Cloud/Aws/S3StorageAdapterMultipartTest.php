<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cloud\Aws;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\Aws\S3StorageAdapter;
use Pulsar\Cloud\CloudHttpClientInterface;
use Pulsar\Cloud\CloudHttpResponse;
use Pulsar\Storage\StorageException;

use function str_contains;
use function str_repeat;

#[CoversClass(S3StorageAdapter::class)]
final class S3StorageAdapterMultipartTest extends TestCase
{
    // One byte over the 5 MB threshold forces the multipart path with two parts.
    private const int OVERSIZE = 5 * 1024 * 1024 + 1;

    #[Test]
    public function multipartUploadSendsTheRealPartETagsNotFabricatedOnes(): void
    {
        $completeBody = '';
        $client = $this->fakeClient(partEtag: '"9b2cf535f27731c974343645a3985328"', completeBody: $completeBody);

        $adapter = new S3StorageAdapter(
            new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET'),
            'my-bucket',
            httpClient: $client,
        );

        $adapter->put('big.bin', str_repeat('x', self::OVERSIZE));

        // The CompleteMultipartUpload body must carry the ETag S3 returned for
        // each part, XML-escaped with its literal quotes preserved — never the
        // old synthetic "<key>-part-<n>" placeholder that S3 rejects.
        self::assertStringContainsString('<ETag>"9b2cf535f27731c974343645a3985328"</ETag>', $completeBody);
        self::assertStringNotContainsString('-part-', $completeBody);
    }

    #[Test]
    public function multipartUploadFailsClosedWhenAPartHasNoETag(): void
    {
        $completeBody = '';
        // partEtag null => the UploadPart response carries no ETag header.
        $client = $this->fakeClient(partEtag: null, completeBody: $completeBody);

        $adapter = new S3StorageAdapter(
            new AwsConfig(region: 'us-east-1', accessKey: 'AKID', secretKey: 'SECRET'),
            'my-bucket',
            httpClient: $client,
        );

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/no ETag/');

        $adapter->put('big.bin', str_repeat('x', self::OVERSIZE));
    }

    /**
     * A fake S3 transport that walks the multipart sequence: initiate returns an
     * upload id, each UploadPart returns $partEtag in its ETag header (or none),
     * complete captures its request body, and abort/anything else returns 200.
     */
    private function fakeClient(?string $partEtag, string &$completeBody): CloudHttpClientInterface
    {
        $client = $this->createStub(CloudHttpClientInterface::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $url, array $headers = [], string $body = '') use ($partEtag, &$completeBody): CloudHttpResponse {
                if ($method === 'POST' && str_contains($url, 'uploads=')) {
                    return new CloudHttpResponse(
                        200,
                        '<InitiateMultipartUploadResult><UploadId>upload-id-1</UploadId></InitiateMultipartUploadResult>',
                    );
                }

                if ($method === 'PUT' && str_contains($url, 'partNumber=')) {
                    return new CloudHttpResponse(200, '', $partEtag === null ? [] : ['etag' => $partEtag]);
                }

                if ($method === 'POST' && str_contains($url, 'uploadId=')) {
                    $completeBody = $body;

                    return new CloudHttpResponse(200, '<CompleteMultipartUploadResult/>');
                }

                // DELETE (abort) and any other call.
                return new CloudHttpResponse(200, '');
            },
        );

        return $client;
    }
}
