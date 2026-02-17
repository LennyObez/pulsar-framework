<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Transport;

use CurlHandle;
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
use function pack;
use function parse_url;
use function preg_match;
use function sprintf;
use function strlen;
use function strtolower;
use function trim;

use const CURL_HTTP_VERSION_2_0;
use const CURLINFO_HTTP_CODE;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTP_VERSION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * Sends OTLP data over gRPC (HTTP/2 + Protobuf with gRPC framing).
 */
#[Internal]
final class GrpcTransport implements OtlpTransportInterface
{
    /** gRPC status code: OK */
    private const int GRPC_STATUS_OK = 0;

    /** gRPC status codes that indicate a transient/retryable failure. */
    private const array RETRYABLE_GRPC_CODES = [
        4,  // DEADLINE_EXCEEDED
        8,  // RESOURCE_EXHAUSTED
        14, // UNAVAILABLE
    ];

    /** @var array<string, string> */
    private readonly array $headers;

    private readonly bool $isInsecure;

    private bool $tlsWarningLogged = false;

    /**
     * @param array<string, string> $headers Additional headers (metadata) for every request
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

        $url = $this->endpoint . $path;
        $frame = self::buildGrpcFrame($protobufPayload);
        $curlHeaders = self::buildHeaderLines($this->headers);

        $grpcStatus = null;
        $grpcMessage = '';

        $ch = curl_init();

        if ($ch === false) {
            return TransportResult::failure(httpStatus: 0, message: 'Failed to initialize curl');
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $frame,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_HEADERFUNCTION => static function (CurlHandle $ch, string $headerLine) use (&$grpcStatus, &$grpcMessage): int {
                    $parsed = self::parseGrpcHeader($headerLine);

                    if ($parsed !== null) {
                        if ($parsed['key'] === 'grpc-status') {
                            $grpcStatus = (int) $parsed['value'];
                        } elseif ($parsed['key'] === 'grpc-message') {
                            $grpcMessage = $parsed['value'];
                        }
                    }

                    return strlen($headerLine);
                },
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

            if ($httpStatus !== 200) {
                $body = is_string($response) ? $response : '';

                return TransportResult::failure(
                    httpStatus: $httpStatus,
                    message: sprintf('HTTP %d: %s', $httpStatus, $body),
                    retryable: $httpStatus === 502 || $httpStatus === 503 || $httpStatus === 504,
                );
            }

            if ($grpcStatus === null) {
                return TransportResult::failure(
                    httpStatus: $httpStatus,
                    message: 'gRPC response missing grpc-status trailer',
                );
            }

            if ($grpcStatus === self::GRPC_STATUS_OK) {
                return TransportResult::success($httpStatus);
            }

            return TransportResult::failure(
                httpStatus: $httpStatus,
                message: sprintf('gRPC status %d: %s', $grpcStatus, $grpcMessage),
                retryable: self::isRetryableGrpcCode($grpcStatus),
            );
        } finally {
            unset($ch);
        }
    }

    /**
     * Builds a gRPC length-prefixed frame.
     *
     * Format: 1 byte compressed flag (0x00) + 4 bytes big-endian payload length + payload.
     */
    public static function buildGrpcFrame(string $payload): string
    {
        return "\x00" . pack('N', strlen($payload)) . $payload;
    }

    /**
     * Parses a single HTTP header line for gRPC trailer keys.
     *
     * @return array{key: string, value: string}|null
     */
    public static function parseGrpcHeader(string $headerLine): ?array
    {
        if (preg_match('/^(grpc-status|grpc-message)\s*:\s*(.+)$/i', trim($headerLine), $matches) === 1) {
            return [
                'key' => strtolower($matches[1]),
                'value' => trim($matches[2]),
            ];
        }

        return null;
    }

    /**
     * Determines whether the given gRPC status code indicates a retryable failure.
     */
    public static function isRetryableGrpcCode(int $code): bool
    {
        return in_array($code, self::RETRYABLE_GRPC_CODES, true);
    }

    /**
     * Builds the complete list of HTTP headers for the gRPC request.
     *
     * @param array<string, string> $customHeaders
     * @return list<string>
     */
    public static function buildHeaderLines(array $customHeaders): array
    {
        $headers = [
            'content-type: application/grpc',
            'te: trailers',
        ];

        foreach ($customHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }
}
