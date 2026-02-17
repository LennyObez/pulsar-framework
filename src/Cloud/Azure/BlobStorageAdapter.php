<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Azure;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\Azure\Config\AzureConfig;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageMetadata;
use Pulsar\Storage\StorageObject;

use function libxml_use_internal_errors;
use function ltrim;
use function rawurlencode;
use function simplexml_load_string;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtotime;
use function substr;
use function trim;

use const LIBXML_NONET;

/**
 * Azure Blob Storage adapter using raw HTTP with OAuth2 bearer tokens.
 *
 * Uses the Azure Blob Service REST API for object operations.
 * Supports account-level and container-level operations.
 */
#[Internal]
final readonly class BlobStorageAdapter implements StorageAdapterInterface
{
    public function __construct(
        private AzureConfig $config,
        private string $storageAccount,
        private string $container,
        private string $prefix = '',
        private CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    #[Override]
    public function put(string $key, string $content, ?StorageMetadata $metadata = null): void
    {
        $blobName = $this->buildBlobName($key);
        $url = $this->blobUrl($blobName);

        $headers = $this->authHeaders();
        $headers['Content-Length'] = (string) strlen($content);
        $headers['x-ms-blob-type'] = 'BlockBlob';
        $headers['x-ms-version'] = '2023-11-03';

        if ($metadata?->contentType !== null) {
            $headers['Content-Type'] = $metadata->contentType;
        } else {
            $headers['Content-Type'] = 'application/octet-stream';
        }

        if ($metadata?->cacheControl !== null) {
            $headers['Cache-Control'] = $metadata->cacheControl;
        }

        try {
            $response = $this->httpClient->request('PUT', $url, $headers, $content);
        } catch (CloudException $e) {
            throw StorageException::writeFailed($key, $e->getMessage());
        }

        if (!$response->isSuccess()) {
            throw StorageException::writeFailed($key, sprintf('Azure Blob returned HTTP %d', $response->statusCode));
        }
    }

    #[Override]
    #[NoDiscard]
    public function get(string $key): string
    {
        $blobName = $this->buildBlobName($key);
        $url = $this->blobUrl($blobName);

        $headers = $this->authHeaders();
        $headers['x-ms-version'] = '2023-11-03';

        try {
            $response = $this->httpClient->request('GET', $url, $headers);
        } catch (CloudException $e) {
            throw StorageException::readFailed($key, $e->getMessage());
        }

        if ($response->statusCode === 404) {
            throw StorageException::objectNotFound($key);
        }

        if (!$response->isSuccess()) {
            throw StorageException::readFailed($key, sprintf('Azure Blob returned HTTP %d', $response->statusCode));
        }

        return $response->body;
    }

    #[Override]
    public function exists(string $key): bool
    {
        $blobName = $this->buildBlobName($key);
        $url = $this->blobUrl($blobName);

        $headers = $this->authHeaders();
        $headers['x-ms-version'] = '2023-11-03';

        try {
            $response = $this->httpClient->request('HEAD', $url, $headers);
        } catch (CloudException) {
            return false;
        }

        return $response->statusCode === 200;
    }

    #[Override]
    public function delete(string $key): void
    {
        $blobName = $this->buildBlobName($key);
        $url = $this->blobUrl($blobName);

        $headers = $this->authHeaders();
        $headers['x-ms-version'] = '2023-11-03';

        try {
            $response = $this->httpClient->request('DELETE', $url, $headers);
        } catch (CloudException $e) {
            throw StorageException::deleteFailed($key, $e->getMessage());
        }

        if ($response->statusCode !== 202 && $response->statusCode !== 200 && $response->statusCode !== 404) {
            throw StorageException::deleteFailed($key, sprintf('Azure Blob returned HTTP %d', $response->statusCode));
        }
    }

    #[Override]
    public function list(string $prefix = ''): array
    {
        $fullPrefix = $this->prefix !== '' ? trim($this->prefix, '/') . '/' : '';
        $fullPrefix .= $prefix;

        $url = sprintf(
            'https://%s.blob.core.windows.net/%s?restype=container&comp=list',
            $this->storageAccount,
            $this->container,
        );

        if ($fullPrefix !== '') {
            $url .= '&prefix=' . rawurlencode($fullPrefix);
        }

        $headers = $this->authHeaders();
        $headers['x-ms-version'] = '2023-11-03';

        try {
            $response = $this->httpClient->request('GET', $url, $headers);
        } catch (CloudException $e) {
            throw StorageException::readFailed($prefix, $e->getMessage());
        }

        if (!$response->isSuccess()) {
            throw StorageException::readFailed($prefix, sprintf('Azure list returned HTTP %d', $response->statusCode));
        }

        return $this->parseListResponse($response->body);
    }

    #[Override]
    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        // Azure SAS tokens require the storage account key for HMAC signing,
        // which is not available in the OAuth2-only configuration.
        return null;
    }

    private function buildBlobName(string $key): string
    {
        $key = ltrim($key, '/');

        if ($this->prefix !== '') {
            return trim($this->prefix, '/') . '/' . $key;
        }

        return $key;
    }

    private function blobUrl(string $blobName): string
    {
        $baseUrl = $this->config->endpoint
            ?? sprintf('https://%s.blob.core.windows.net', $this->storageAccount);

        return sprintf('%s/%s/%s', $baseUrl, $this->container, rawurlencode($blobName));
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = $this->config->resolveAccessToken();

        if ($token === '') {
            throw StorageException::connectionFailed('Azure credentials not configured');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
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

        $objects = [];
        $blobs = $doc->Blobs->Blob ?? [];

        foreach ($blobs as $blob) {
            /** @psalm-suppress TypeDoesNotContainType */
            $key = (string) ($blob->Name ?? '');

            if ($this->prefix !== '' && str_starts_with($key, trim($this->prefix, '/') . '/')) {
                $key = substr($key, strlen(trim($this->prefix, '/')) + 1);
            }

            /** @psalm-suppress TypeDoesNotContainType */
            $size = (int) ($blob->Properties->{'Content-Length'} ?? 0);
            /** @psalm-suppress TypeDoesNotContainType */
            $lastModified = (int) strtotime((string) ($blob->Properties->{'Last-Modified'} ?? '0'));
            /** @psalm-suppress TypeDoesNotContainType */
            $contentType = (string) ($blob->Properties->{'Content-Type'} ?? '');

            $objects[] = new StorageObject(
                key: $key,
                size: $size,
                lastModified: $lastModified,
                contentType: $contentType !== '' ? $contentType : null,
            );
        }

        return $objects;
    }
}
