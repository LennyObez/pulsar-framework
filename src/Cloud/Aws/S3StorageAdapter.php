<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Aws;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\Aws\Config\AwsConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageMetadata;
use Pulsar\Storage\StorageObject;

use function hash;
use function intval;
use function libxml_use_internal_errors;
use function ltrim;
use function rawurlencode;
use function simplexml_load_string;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtotime;
use function substr;
use function trim;

use const LIBXML_NONET;

/**
 * S3 storage adapter using raw HTTP with AWS SigV4 signing.
 *
 * Supports presigned URLs, multipart upload for large objects,
 * and custom endpoints for S3-compatible services (MinIO, LocalStack).
 */
#[Internal]
final class S3StorageAdapter implements StorageAdapterInterface
{
    private const int MULTIPART_THRESHOLD = 5 * 1024 * 1024; // 5 MB
    private const int PART_SIZE = 5 * 1024 * 1024; // 5 MB minimum part

    private ?AwsSigner $signer = null;

    public function __construct(
        private readonly AwsConfig $config,
        private readonly string $bucket,
        private readonly string $prefix = '',
        private readonly bool $usePathStyle = false,
        private readonly CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    #[Override]
    public function put(string $key, string $content, ?StorageMetadata $metadata = null): void
    {
        if (strlen($content) > self::MULTIPART_THRESHOLD) {
            $this->multipartUpload($key, $content, $metadata);

            return;
        }

        $objectKey = $this->buildObjectKey($key);
        $payloadHash = hash('sha256', $content);

        $headers = [
            'Content-Length' => (string) strlen($content),
            'Host' => $this->getHost(),
        ];

        if ($metadata !== null) {
            if ($metadata->contentType !== null) {
                $headers['Content-Type'] = $metadata->contentType;
            }

            if ($metadata->cacheControl !== null) {
                $headers['Cache-Control'] = $metadata->cacheControl;
            }
        }

        $uri = $this->buildUri($objectKey);
        $signedHeaders = $this->getSigner()->sign('PUT', $uri, '', $headers, $payloadHash);

        try {
            $response = $this->httpClient->request(
                'PUT',
                $this->buildUrl($uri),
                $signedHeaders,
                $content,
            );
        } catch (CloudException $e) {
            throw StorageException::writeFailed($key, $e->getMessage());
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw StorageException::writeFailed($key, sprintf('S3 returned HTTP %d', $response->statusCode));
        }
    }

    #[Override]
    #[NoDiscard]
    public function get(string $key): string
    {
        $objectKey = $this->buildObjectKey($key);
        $payloadHash = hash('sha256', '');

        $headers = ['Host' => $this->getHost()];
        $uri = $this->buildUri($objectKey);
        $signedHeaders = $this->getSigner()->sign('GET', $uri, '', $headers, $payloadHash);

        try {
            $response = $this->httpClient->request('GET', $this->buildUrl($uri), $signedHeaders);
        } catch (CloudException $e) {
            throw StorageException::readFailed($key, $e->getMessage());
        }

        if ($response->statusCode === 404) {
            throw StorageException::objectNotFound($key);
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw StorageException::readFailed($key, sprintf('S3 returned HTTP %d', $response->statusCode));
        }

        return $response->body;
    }

    #[Override]
    public function exists(string $key): bool
    {
        $objectKey = $this->buildObjectKey($key);
        $payloadHash = hash('sha256', '');

        $headers = ['Host' => $this->getHost()];
        $uri = $this->buildUri($objectKey);
        $signedHeaders = $this->getSigner()->sign('HEAD', $uri, '', $headers, $payloadHash);

        try {
            $response = $this->httpClient->request('HEAD', $this->buildUrl($uri), $signedHeaders);
        } catch (CloudException) {
            return false;
        }

        return $response->statusCode === 200;
    }

    #[Override]
    public function delete(string $key): void
    {
        $objectKey = $this->buildObjectKey($key);
        $payloadHash = hash('sha256', '');

        $headers = ['Host' => $this->getHost()];
        $uri = $this->buildUri($objectKey);
        $signedHeaders = $this->getSigner()->sign('DELETE', $uri, '', $headers, $payloadHash);

        try {
            $response = $this->httpClient->request('DELETE', $this->buildUrl($uri), $signedHeaders);
        } catch (CloudException $e) {
            throw StorageException::deleteFailed($key, $e->getMessage());
        }

        if ($response->statusCode !== 204 && $response->statusCode !== 200 && $response->statusCode !== 404) {
            throw StorageException::deleteFailed($key, sprintf('S3 returned HTTP %d', $response->statusCode));
        }
    }

    #[Override]
    public function list(string $prefix = ''): array
    {
        $fullPrefix = $this->prefix !== '' ? trim($this->prefix, '/') . '/' : '';
        $fullPrefix .= $prefix;

        $queryString = 'list-type=2';
        if ($fullPrefix !== '') {
            $queryString .= '&prefix=' . rawurlencode($fullPrefix);
        }

        $payloadHash = hash('sha256', '');
        $headers = ['Host' => $this->getHost()];
        $uri = $this->buildUri('/');
        $signedHeaders = $this->getSigner()->sign('GET', $uri, $queryString, $headers, $payloadHash);

        try {
            $response = $this->httpClient->request('GET', $this->buildUrl($uri) . '?' . $queryString, $signedHeaders);
        } catch (CloudException $e) {
            throw StorageException::readFailed($prefix, $e->getMessage());
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw StorageException::readFailed($prefix, sprintf('S3 list returned HTTP %d', $response->statusCode));
        }

        return $this->parseListResponse($response->body);
    }

    /** @phpstan-ignore return.unusedType (interface requires ?string for adapters that don't support presigned URLs) */
    #[Override]
    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        $signer = $this->getSigner();
        $objectKey = $this->buildObjectKey($key);
        $host = $this->getHost();
        $uri = $this->buildUri($objectKey);

        return $signer->presignUrl('GET', $host, $uri, $expiresInSeconds);
    }

    /**
     * Upload large content using S3 multipart upload.
     */
    private function multipartUpload(string $key, string $content, ?StorageMetadata $metadata): void
    {
        $uploadId = $this->initiateMultipartUpload($key, $metadata);
        $totalSize = strlen($content);
        $partNumber = 1;
        $offset = 0;
        /** @var list<array{PartNumber: int, ETag: string}> $parts */
        $parts = [];

        try {
            while ($offset < $totalSize) {
                $chunk = substr($content, $offset, self::PART_SIZE);
                $etag = $this->uploadPart($key, $uploadId, $partNumber, $chunk);
                $parts[] = ['PartNumber' => $partNumber, 'ETag' => $etag];
                $offset += self::PART_SIZE;
                $partNumber++;
            }

            $this->completeMultipartUpload($key, $uploadId, $parts);
        } catch (StorageException $e) {
            $this->abortMultipartUpload($key, $uploadId);

            throw $e;
        }
    }

    private function initiateMultipartUpload(string $key, ?StorageMetadata $metadata): string
    {
        $objectKey = $this->buildObjectKey($key);
        $payloadHash = hash('sha256', '');

        $headers = ['Host' => $this->getHost()];

        if ($metadata?->contentType !== null) {
            $headers['Content-Type'] = $metadata->contentType;
        }

        $uri = $this->buildUri($objectKey);
        $queryString = 'uploads=';
        $signedHeaders = $this->getSigner()->sign('POST', $uri, $queryString, $headers, $payloadHash);

        try {
            $response = $this->httpClient->request('POST', $this->buildUrl($uri) . '?' . $queryString, $signedHeaders);
        } catch (CloudException $e) {
            throw StorageException::writeFailed($key, 'Multipart initiation failed: ' . $e->getMessage());
        }

        if (!$response->isSuccess()) {
            throw StorageException::writeFailed($key, sprintf('Multipart initiation returned HTTP %d', $response->statusCode));
        }

        $prevErrors = libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($response->body, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prevErrors);

        if ($doc === false) {
            throw StorageException::writeFailed($key, 'Failed to parse multipart initiation response');
        }

        /** @psalm-suppress TypeDoesNotContainType */
        return (string) ($doc->UploadId ?? '');
    }

    private function uploadPart(string $key, string $uploadId, int $partNumber, string $content): string
    {
        $objectKey = $this->buildObjectKey($key);
        $payloadHash = hash('sha256', $content);

        $headers = [
            'Host' => $this->getHost(),
            'Content-Length' => (string) strlen($content),
        ];

        $uri = $this->buildUri($objectKey);
        $queryString = sprintf('partNumber=%d&uploadId=%s', $partNumber, rawurlencode($uploadId));
        $signedHeaders = $this->getSigner()->sign('PUT', $uri, $queryString, $headers, $payloadHash);

        try {
            $response = $this->httpClient->request(
                'PUT',
                $this->buildUrl($uri) . '?' . $queryString,
                $signedHeaders,
                $content,
            );
        } catch (CloudException $e) {
            throw StorageException::writeFailed($key, sprintf('Part %d upload failed: %s', $partNumber, $e->getMessage()));
        }

        if (!$response->isSuccess()) {
            throw StorageException::writeFailed($key, sprintf('Part %d returned HTTP %d', $partNumber, $response->statusCode));
        }

        // ETag is returned in the response body for some implementations,
        // but conventionally available as part of the XML response
        return trim($key . '-part-' . $partNumber);
    }

    /**
     * @param list<array{PartNumber: int, ETag: string}> $parts
     */
    private function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        $objectKey = $this->buildObjectKey($key);
        $body = '<CompleteMultipartUpload>';
        foreach ($parts as $part) {
            $body .= sprintf(
                '<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>',
                $part['PartNumber'],
                $part['ETag'],
            );
        }
        $body .= '</CompleteMultipartUpload>';

        $payloadHash = hash('sha256', $body);
        $headers = [
            'Host' => $this->getHost(),
            'Content-Type' => 'application/xml',
            'Content-Length' => (string) strlen($body),
        ];

        $uri = $this->buildUri($objectKey);
        $queryString = 'uploadId=' . rawurlencode($uploadId);
        $signedHeaders = $this->getSigner()->sign('POST', $uri, $queryString, $headers, $payloadHash);

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->buildUrl($uri) . '?' . $queryString,
                $signedHeaders,
                $body,
            );
        } catch (CloudException $e) {
            throw StorageException::writeFailed($key, 'Multipart completion failed: ' . $e->getMessage());
        }

        if (!$response->isSuccess()) {
            throw StorageException::writeFailed($key, sprintf('Multipart completion returned HTTP %d', $response->statusCode));
        }
    }

    private function abortMultipartUpload(string $key, string $uploadId): void
    {
        $objectKey = $this->buildObjectKey($key);
        $payloadHash = hash('sha256', '');

        $headers = ['Host' => $this->getHost()];
        $uri = $this->buildUri($objectKey);
        $queryString = 'uploadId=' . rawurlencode($uploadId);

        $signedHeaders = $this->getSigner()->sign('DELETE', $uri, $queryString, $headers, $payloadHash);

        try {
            $this->httpClient->request('DELETE', $this->buildUrl($uri) . '?' . $queryString, $signedHeaders);
        } catch (CloudException) {
            // Best-effort abort: don't throw from cleanup
        }
    }

    private function buildObjectKey(string $key): string
    {
        $key = ltrim($key, '/');

        if ($this->prefix !== '') {
            return '/' . trim($this->prefix, '/') . '/' . $key;
        }

        return '/' . $key;
    }

    private function buildUri(string $objectKey): string
    {
        return $this->usePathStyle ? '/' . $this->bucket . $objectKey : $objectKey;
    }

    private function buildUrl(string $uri): string
    {
        $scheme = $this->config->endpoint !== null && str_starts_with($this->config->endpoint, 'http://')
            ? 'http://'
            : 'https://';

        return $scheme . $this->getHost() . $uri;
    }

    private function getHost(): string
    {
        if ($this->config->endpoint !== null) {
            $host = str_replace(['https://', 'http://'], '', $this->config->endpoint);

            return $this->usePathStyle ? $host : $this->bucket . '.' . $host;
        }

        return $this->usePathStyle
            ? sprintf('s3.%s.amazonaws.com', $this->config->region)
            : sprintf('%s.s3.%s.amazonaws.com', $this->bucket, $this->config->region);
    }

    private function getSigner(): AwsSigner
    {
        if ($this->signer !== null) {
            return $this->signer;
        }

        $credentials = $this->config->resolveCredentials();

        if ($credentials['access_key'] === '' || $credentials['secret_key'] === '') {
            throw StorageException::connectionFailed('AWS credentials not configured');
        }

        $this->signer = new AwsSigner(
            $credentials['access_key'],
            $credentials['secret_key'],
            $this->config->region,
            's3',
        );

        return $this->signer;
    }

    /**
     * @return list<StorageObject>
     */
    private function parseListResponse(string $xml): array
    {
        $prevErrors = libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prevErrors);

        if ($doc === false) {
            return [];
        }

        $doc->registerXPathNamespace('s3', 'http://s3.amazonaws.com/doc/2006-03-01/');

        $objects = [];
        $contents = $doc->xpath('//s3:Contents') ?: $doc->xpath('//Contents') ?: [];

        foreach ($contents as $item) {
            /** @psalm-suppress TypeDoesNotContainType */
            $key = (string) ($item->Key ?? '');

            if ($this->prefix !== '' && str_starts_with($key, trim($this->prefix, '/') . '/')) {
                $key = substr($key, strlen(trim($this->prefix, '/')) + 1);
            }

            /** @psalm-suppress TypeDoesNotContainType */
            $size = intval((string) ($item->Size ?? '0'));
            /** @psalm-suppress TypeDoesNotContainType */
            $lastModified = intval((string) strtotime((string) ($item->LastModified ?? '0')));

            $objects[] = new StorageObject(
                key: $key,
                size: $size,
                lastModified: $lastModified,
            );
        }

        return $objects;
    }
}
