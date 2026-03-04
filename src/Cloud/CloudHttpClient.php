<?php

declare(strict_types=1);

namespace Pulsar\Cloud;

use Pulsar\Api\Internal;

use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function is_string;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CUSTOMREQUEST;
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
final readonly class CloudHttpClient
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

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
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

            throw CloudException::connectionFailed('http', $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return new CloudHttpResponse(
            statusCode: $statusCode,
            body: is_string($responseBody) ? $responseBody : '',
        );
    }
}
