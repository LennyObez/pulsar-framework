<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Transport;

use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;

use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function in_array;
use function is_string;
use function parse_url;
use function sprintf;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Sends OTLP data over HTTP/1.1 with Protobuf content encoding.
 */
#[Internal]
final class HttpProtobufTransport implements OtlpTransportInterface
{
    private const array RETRYABLE_HTTP_CODES = [429, 502, 503, 504];

    /** @var array<string, string> */
    private readonly array $headers;

    private readonly bool $isInsecure;

    private bool $tlsWarningLogged = false;

    /**
     * @param array<string, string> $headers Additional headers to include with every request
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly int $timeoutMs = 5000,
        array $headers = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $host = parse_url($endpoint, PHP_URL_HOST);

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException(
                sprintf('Invalid OTLP endpoint URL: scheme must be http or https, got "%s"', $endpoint),
            );
        }

        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException(
                sprintf('Invalid OTLP endpoint URL: host is empty in "%s"', $endpoint),
            );
        }

        $this->headers = $headers;
        $this->isInsecure = $scheme === 'http';
    }

    #[Override]
    public function send(string $path, string $protobufPayload): TransportResult
    {
        if ($this->isInsecure && !$this->tlsWarningLogged) {
            $this->logger->warning('OpenTelemetry OTLP endpoint uses insecure HTTP transport (no TLS)', [
                'endpoint' => $this->endpoint,
            ]);
            $this->tlsWarningLogged = true;
        }

        /** @var non-empty-string $url */
        $url = $this->endpoint . $path;
        $curlHeaders = self::buildHeaderLines($this->headers);

        $ch = curl_init();

        if ($ch === false) {
            return TransportResult::failure(httpStatus: 0, message: 'Failed to initialize curl');
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $protobufPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                CURLOPT_HTTPHEADER => $curlHeaders,
            ]);

            $response = curl_exec($ch);

            if ($response === false) {
                return TransportResult::failure(
                    httpStatus: 0,
                    message: sprintf('curl error %d: %s', curl_errno($ch), curl_error($ch)),
                    retryable: true,
                );
            }

            /** @var int $httpStatus */
            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpStatus >= 200 && $httpStatus < 300) {
                return TransportResult::success($httpStatus);
            }

            $body = is_string($response) ? $response : '';

            return TransportResult::failure(
                httpStatus: $httpStatus,
                message: sprintf('HTTP %d: %s', $httpStatus, $body),
                retryable: self::isRetryableHttpCode($httpStatus),
            );
        } finally {
            unset($ch);
        }
    }

    /**
     * Builds the complete list of HTTP headers for the request.
     *
     * @param array<string, string> $customHeaders
     * @return list<string>
     */
    public static function buildHeaderLines(array $customHeaders): array
    {
        $headers = ['Content-Type: application/x-protobuf'];

        foreach ($customHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }

    /**
     * Determines whether the given HTTP status code indicates a retryable failure.
     */
    public static function isRetryableHttpCode(int $code): bool
    {
        return in_array($code, self::RETRYABLE_HTTP_CODES, true);
    }
}
