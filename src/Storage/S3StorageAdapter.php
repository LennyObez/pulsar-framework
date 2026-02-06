<?php

declare(strict_types=1);

namespace Pulsar\Storage;

use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOBODY;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_URL;

use function hash;
use function is_string;
use function ltrim;
use function simplexml_load_string;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * S3-compatible storage adapter using raw cURL and AWS SigV4.
 */
final class S3StorageAdapter implements StorageAdapterInterface
{
    private ?S3Signer $signer = null;

    public function __construct(
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $prefix = '',
        private readonly ?string $endpoint = null,
        private readonly bool $usePathStyle = false,
        private readonly ?string $accessKey = null,
        private readonly ?string $secretKey = null,
    ) {}

    public function put(string $key, string $content, ?StorageMetadata $metadata = null): void
    {
        $objectKey = $this->buildObjectKey($key);
        $payloadHash = hash('sha256', $content);

        $headers = ['Content-Length' => (string) strlen($content)];

        if ($metadata !== null) {
            if ($metadata->contentType !== null) {
                $headers['Content-Type'] = $metadata->contentType;
            }

            if ($metadata->cacheControl !== null) {
                $headers['Cache-Control'] = $metadata->cacheControl;
            }
        }

        /** @var array<string, string> $headers */
        $response = $this->request('PUT', $objectKey, $content, $headers, $payloadHash);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw StorageException::writeFailed($key, sprintf('S3 returned HTTP %d', $response['status']));
        }
    }

    public function get(string $key): string
    {
        $objectKey = $this->buildObjectKey($key);
        $response = $this->request('GET', $objectKey);

        if ($response['status'] === 404) {
            throw StorageException::objectNotFound($key);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw StorageException::readFailed($key, sprintf('S3 returned HTTP %d', $response['status']));
        }

        return $response['body'];
    }

    public function exists(string $key): bool
    {
        $objectKey = $this->buildObjectKey($key);
        $response = $this->request('HEAD', $objectKey);

        return $response['status'] === 200;
    }

    public function delete(string $key): void
    {
        $objectKey = $this->buildObjectKey($key);
        $response = $this->request('DELETE', $objectKey);

        if ($response['status'] !== 204 && $response['status'] !== 200 && $response['status'] !== 404) {
            throw StorageException::deleteFailed($key, sprintf('S3 returned HTTP %d', $response['status']));
        }
    }

    public function list(string $prefix = ''): array
    {
        $fullPrefix = $this->prefix !== '' ? trim($this->prefix, '/') . '/' : '';
        $fullPrefix .= $prefix;

        $queryString = 'list-type=2';
        if ($fullPrefix !== '') {
            $queryString .= '&prefix=' . rawurlencode($fullPrefix);
        }

        $response = $this->request('GET', '/', '', [], hash('sha256', ''), $queryString);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw StorageException::readFailed($prefix, sprintf('S3 list returned HTTP %d', $response['status']));
        }

        return $this->parseListResponse($response['body']);
    }

    /** @phpstan-ignore return.unusedType (interface requires ?string for adapters that don't support presigned URLs) */
    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): ?string
    {
        $signer = $this->getSigner();
        $objectKey = $this->buildObjectKey($key);
        $host = $this->getHost();
        $uri = $this->usePathStyle ? '/' . $this->bucket . $objectKey : $objectKey;

        return $signer->presignUrl('GET', $host, $uri, $expiresInSeconds);
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{status: int, body: string}
     */
    private function request(
        string $method,
        string $objectKey,
        string $body = '',
        array $extraHeaders = [],
        ?string $payloadHash = null,
        string $queryString = '',
    ): array {
        $host = $this->getHost();
        $uri = $this->usePathStyle ? '/' . $this->bucket . $objectKey : $objectKey;
        $url = 'https://' . $host . $uri;

        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $payloadHash ??= hash('sha256', $body);

        $headers = $extraHeaders;
        $headers['Host'] = $host;

        $signer = $this->getSigner();
        $headers = $signer->sign($method, $uri, $queryString, $headers, $payloadHash);

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $ch = curl_init();

        if ($ch === false) {
            throw StorageException::connectionFailed('curl_init failed');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
        ];

        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        /** @phpstan-ignore argument.type (cURL option array types are overly strict in PHPStan stubs) */
        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            $error = curl_error($ch);
            curl_close($ch);
            throw StorageException::connectionFailed($error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $statusCode,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    private function buildObjectKey(string $key): string
    {
        $key = ltrim($key, '/');

        if ($this->prefix !== '') {
            return '/' . trim($this->prefix, '/') . '/' . $key;
        }

        return '/' . $key;
    }

    private function getHost(): string
    {
        if ($this->endpoint !== null) {
            $host = str_replace(['https://', 'http://'], '', $this->endpoint);

            return $this->usePathStyle ? $host : $this->bucket . '.' . $host;
        }

        return $this->usePathStyle
            ? sprintf('s3.%s.amazonaws.com', $this->region)
            : sprintf('%s.s3.%s.amazonaws.com', $this->bucket, $this->region);
    }

    private function getSigner(): S3Signer
    {
        if ($this->signer !== null) {
            return $this->signer;
        }

        $accessKey = $this->accessKey ?? getenv('AWS_ACCESS_KEY_ID');
        $secretKey = $this->secretKey ?? getenv('AWS_SECRET_ACCESS_KEY');

        if ($accessKey === false || $accessKey === '' || $secretKey === false || $secretKey === '') {
            throw StorageException::connectionFailed('AWS credentials not configured');
        }

        $this->signer = new S3Signer($accessKey, $secretKey, $this->region);

        return $this->signer;
    }

    /**
     * @return list<StorageObject>
     */
    private function parseListResponse(string $xml): array
    {
        $doc = @simplexml_load_string($xml);

        if ($doc === false) {
            return [];
        }

        // Strip namespace for easier traversal
        $doc->registerXPathNamespace('s3', 'http://s3.amazonaws.com/doc/2006-03-01/');

        $objects = [];
        $contents = $doc->xpath('//s3:Contents') ?: $doc->xpath('//Contents') ?: [];

        foreach ($contents as $item) {
            /** @psalm-suppress TypeDoesNotContainType */
            $key = (string) ($item->Key ?? '');

            // Remove prefix from the key for relative paths
            if ($this->prefix !== '' && str_starts_with($key, trim($this->prefix, '/') . '/')) {
                $key = substr($key, strlen(trim($this->prefix, '/')) + 1);
            }

            /** @psalm-suppress TypeDoesNotContainType */
            $size = (int) ($item->Size ?? 0);
            /** @psalm-suppress TypeDoesNotContainType */
            $lastModified = (int) strtotime((string) ($item->LastModified ?? '0'));

            $objects[] = new StorageObject(
                key: $key,
                size: $size,
                lastModified: $lastModified,
            );
        }

        return $objects;
    }
}
