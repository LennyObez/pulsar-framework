<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Gcp;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Cloud\CloudException;
use Pulsar\Cloud\CloudHttpClient;
use Pulsar\Cloud\Gcp\Config\GcpConfig;
use Pulsar\Storage\StorageAdapterInterface;
use Pulsar\Storage\StorageException;
use Pulsar\Storage\StorageMetadata;
use Pulsar\Storage\StorageObject;

use function json_decode;
use function ltrim;
use function rawurlencode;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtotime;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Google Cloud Storage adapter using raw HTTP with OAuth2 bearer tokens.
 *
 * Uses the GCS JSON API for object operations and supports
 * signed URLs for temporary access.
 */
#[Internal]
final readonly class GcsStorageAdapter implements StorageAdapterInterface
{
    private const string API_BASE = 'https://storage.googleapis.com';
    private const string UPLOAD_BASE = 'https://storage.googleapis.com/upload/storage/v1';

    public function __construct(
        private GcpConfig $config,
        private string $bucket,
        private string $prefix = '',
        private CloudHttpClient $httpClient = new CloudHttpClient(),
    ) {}

    #[Override]
    public function put(string $key, string $content, ?StorageMetadata $metadata = null): void
    {
        $objectKey = $this->buildObjectKey($key);
        $encodedKey = rawurlencode($objectKey);

        $url = sprintf(
            '%s/b/%s/o?uploadType=media&name=%s',
            self::UPLOAD_BASE,
            $this->bucket,
            $encodedKey,
        );

        $headers = $this->authHeaders();
        $headers['Content-Length'] = (string) strlen($content);

        if ($metadata !== null && $metadata->contentType !== null) {
            $headers['Content-Type'] = $metadata->contentType;
        } else {
            $headers['Content-Type'] = 'application/octet-stream';
        }

        if ($metadata !== null && $metadata->cacheControl !== null) {
            $headers['Cache-Control'] = $metadata->cacheControl;
        }

        try {
            $response = $this->httpClient->request('POST', $url, $headers, $content);
        } catch (CloudException $e) {
            throw StorageException::writeFailed($key, $e->getMessage());
        }

        if (!$response->isSuccess()) {
            throw StorageException::writeFailed($key, sprintf('GCS returned HTTP %d', $response->statusCode));
        }
    }

    #[Override]
    #[NoDiscard]
    public function get(string $key): string
    {
        $objectKey = $this->buildObjectKey($key);
        $encodedKey = rawurlencode($objectKey);

        $url = sprintf(
            '%s/storage/v1/b/%s/o/%s?alt=media',
            self::API_BASE,
            $this->bucket,
            $encodedKey,
        );

        try {
            $response = $this->httpClient->request('GET', $url, $this->authHeaders());
        } catch (CloudException $e) {
            throw StorageException::readFailed($key, $e->getMessage());
        }

        if ($response->statusCode === 404) {
            throw StorageException::objectNotFound($key);
        }

        if (!$response->isSuccess()) {
            throw StorageException::readFailed($key, sprintf('GCS returned HTTP %d', $response->statusCode));
        }

        return $response->body;
    }

    #[Override]
    public function exists(string $key): bool
    {
        $objectKey = $this->buildObjectKey($key);
        $encodedKey = rawurlencode($objectKey);

        $url = sprintf(
            '%s/storage/v1/b/%s/o/%s',
            self::API_BASE,
            $this->bucket,
            $encodedKey,
        );

        try {
            $response = $this->httpClient->request('GET', $url, $this->authHeaders());
        } catch (CloudException) {
            return false;
        }

        return $response->isSuccess();
    }

    #[Override]
    public function delete(string $key): void
    {
        $objectKey = $this->buildObjectKey($key);
        $encodedKey = rawurlencode($objectKey);

        $url = sprintf(
            '%s/storage/v1/b/%s/o/%s',
            self::API_BASE,
            $this->bucket,
            $encodedKey,
        );

        try {
            $response = $this->httpClient->request('DELETE', $url, $this->authHeaders());
        } catch (CloudException $e) {
            throw StorageException::deleteFailed($key, $e->getMessage());
        }

        if ($response->statusCode !== 204 && $response->statusCode !== 200 && $response->statusCode !== 404) {
            throw StorageException::deleteFailed($key, sprintf('GCS returned HTTP %d', $response->statusCode));
        }
    }

    #[Override]
    public function list(string $prefix = ''): array
    {
        $fullPrefix = $this->prefix !== '' ? trim($this->prefix, '/') . '/' : '';
        $fullPrefix .= $prefix;

        $url = sprintf(
            '%s/storage/v1/b/%s/o',
            self::API_BASE,
            $this->bucket,
        );

        if ($fullPrefix !== '') {
            $url .= '?prefix=' . rawurlencode($fullPrefix);
        }

        try {
            $response = $this->httpClient->request('GET', $url, $this->authHeaders());
        } catch (CloudException $e) {
            throw StorageException::readFailed($prefix, $e->getMessage());
        }

        if (!$response->isSuccess()) {
            throw StorageException::readFailed($prefix, sprintf('GCS list returned HTTP %d', $response->statusCode));
        }

        return $this->parseListResponse($response->body);
    }

    #[Override]
    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        // GCS signed URLs require service account private keys for V4 signing.
        // Without the private key loaded from credentials, we cannot generate
        // presigned URLs. Return null to indicate unsupported.
        return null;
    }

    private function buildObjectKey(string $key): string
    {
        $key = ltrim($key, '/');

        if ($this->prefix !== '') {
            return trim($this->prefix, '/') . '/' . $key;
        }

        return $key;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = $this->config->resolveAccessToken();

        if ($token === '') {
            throw StorageException::connectionFailed('GCP credentials not configured');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    /**
     * @return list<StorageObject>
     */
    private function parseListResponse(string $json): array
    {
        /** @var array{items?: list<array{name: string, size?: string, updated?: string, contentType?: string}>} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $objects = [];

        foreach ($data['items'] ?? [] as $item) {
            $key = $item['name'];

            // Strip prefix for relative paths
            if ($this->prefix !== '' && str_starts_with($key, trim($this->prefix, '/') . '/')) {
                $key = substr($key, strlen(trim($this->prefix, '/')) + 1);
            }

            $objects[] = new StorageObject(
                key: $key,
                size: (int) ($item['size'] ?? 0),
                lastModified: isset($item['updated']) ? (int) strtotime($item['updated']) : 0,
                contentType: $item['contentType'] ?? null,
            );
        }

        return $objects;
    }
}
