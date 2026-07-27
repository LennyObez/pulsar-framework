<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use CurlHandle;
use Pulsar\Api\Internal;

use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_string;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOBODY;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;

/**
 * Shared cURL-based HTTP client for cloud provider adapters.
 *
 * Encapsulates the raw HTTP mechanics so each adapter only builds
 * headers/payload and processes responses.
 */
#[Internal]
final readonly class CloudHttpClient implements CloudHttpClientInterface
{
    public function __construct(
        private int $timeout = 30,
    ) {}

    /**
     * Send an HTTP request and return the response.
     *
     * @param string               $method  HTTP method
     * @param string               $url     Full URL
     * @param array<string, string> $headers Request headers
     * @param string               $body    Request body
     *
     * @throws CloudException On connection/transport failure
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
    ): CloudHttpResponse {
        $ch = curl_init();

        if ($ch === false) {
            throw CloudException::connectionFailed('http', 'curl_init failed');
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        // Capture response headers (lowercased name => value) so callers can
        // read provider metadata such as the S3 UploadPart ETag, which is only
        // available in the response header — never the body.
        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];
        $headerCallback = static function (CurlHandle $handle, string $headerLine) use (&$responseHeaders): int {
            $colon = strpos($headerLine, ':');
            if ($colon !== false) {
                $name = strtolower(trim(substr($headerLine, 0, $colon)));
                if ($name !== '') {
                    $responseHeaders[$name] = trim(substr($headerLine, $colon + 1));
                }
            }

            return strlen($headerLine);
        };

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADERFUNCTION => $headerCallback,
        ];

        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);
        $responseBody = curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            // curl_close() is a no-op since PHP 8.0 and is formally deprecated on
            // PHP 8.5 (the framework's declared target), so calling it emits
            // E_DEPRECATED on every request -- which lands in the response body
            // under display_errors=On. The CurlHandle frees itself when $ch goes
            // out of scope, exactly as CurlMailHttpClient already relies on.
            throw CloudException::connectionFailed('http', curl_error($ch));
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return new CloudHttpResponse(
            statusCode: $statusCode,
            body: is_string($responseBody) ? $responseBody : '',
            headers: $responseHeaders,
        );
    }
}
