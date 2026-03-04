<?php

declare(strict_types=1);

namespace Pulsar\Cloud\Aws;

use Pulsar\Api\Internal;

use function array_keys;
use function array_map;
use function explode;
use function gmdate;
use function hash;
use function hash_hmac;
use function implode;
use function ksort;
use function rawurlencode;
use function sort;
use function sprintf;
use function strtolower;
use function time;
use function trim;
use function uksort;

/**
 * AWS Signature Version 4 signer for arbitrary AWS services.
 *
 * Generalizes the signing process from S3Signer to support any AWS service
 * (SQS, SES, SNS, CloudWatch, Secrets Manager, etc.).
 */
#[Internal]
final class AwsSigner
{
    private const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service,
    ) {}

    /**
     * Sign a request and return the authorization headers.
     *
     * @param array<string, string> $headers Existing headers (Host must be present)
     * @return array<string, string> Headers including Authorization and x-amz-* headers
     */
    public function sign(
        string $method,
        string $uri,
        string $queryString,
        array $headers,
        string $payloadHash,
        ?int $timestamp = null,
    ): array {
        $timestamp ??= time();
        $dateTime = gmdate('Ymd\THis\Z', $timestamp);
        $dateStamp = gmdate('Ymd', $timestamp);

        $headers['x-amz-date'] = $dateTime;
        $headers['x-amz-content-sha256'] = $payloadHash;

        $canonicalHeaders = $this->buildCanonicalHeaders($headers);
        $signedHeaders = $this->buildSignedHeaders($headers);

        $canonicalRequest = implode("\n", [
            $method,
            $this->uriEncode($uri),
            $queryString,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = sprintf('%s/%s/%s/aws4_request', $dateStamp, $this->region, $this->service);
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $dateTime,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->deriveSigningKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKey,
            $credentialScope,
            $signedHeaders,
            $signature,
        );

        return $headers;
    }

    /**
     * Generate a pre-signed URL for temporary access.
     */
    public function presignUrl(
        string $method,
        string $host,
        string $uri,
        int $expiresInSeconds,
        ?int $timestamp = null,
    ): string {
        $timestamp ??= time();
        $dateTime = gmdate('Ymd\THis\Z', $timestamp);
        $dateStamp = gmdate('Ymd', $timestamp);

        $credentialScope = sprintf('%s/%s/%s/aws4_request', $dateStamp, $this->region, $this->service);

        $queryParams = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => $this->accessKey . '/' . $credentialScope,
            'X-Amz-Date' => $dateTime,
            'X-Amz-Expires' => (string) $expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];

        ksort($queryParams);
        $queryString = $this->buildQueryString($queryParams);

        $canonicalHeaders = 'host:' . $host . "\n";
        $canonicalRequest = implode("\n", [
            $method,
            $this->uriEncode($uri),
            $queryString,
            $canonicalHeaders,
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $dateTime,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = $this->deriveSigningKey($dateStamp);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return sprintf('https://%s%s?%s&X-Amz-Signature=%s', $host, $uri, $queryString, $signature);
    }

    private function deriveSigningKey(string $dateStamp): string
    {
        $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', $this->service, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildCanonicalHeaders(array $headers): string
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = trim($value);
        }
        uksort($normalized, 'strcmp');

        $canonical = '';
        foreach ($normalized as $key => $value) {
            $canonical .= $key . ':' . $value . "\n";
        }

        return $canonical;
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildSignedHeaders(array $headers): string
    {
        $keys = array_keys($headers);
        $keys = array_map(strtolower(...), $keys);
        sort($keys);

        return implode(';', $keys);
    }

    /**
     * @param array<string, string> $params
     */
    private function buildQueryString(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode('&', $parts);
    }

    private function uriEncode(string $uri): string
    {
        $segments = explode('/', $uri);
        $encoded = array_map(static fn(string $s): string => rawurlencode($s), $segments);

        return implode('/', $encoded);
    }

    /**
     * Prevent keys from leaking in debug output.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'accessKey' => '[REDACTED]',
            'secretKey' => '[REDACTED]',
            'region' => $this->region,
            'service' => $this->service,
        ];
    }
}
