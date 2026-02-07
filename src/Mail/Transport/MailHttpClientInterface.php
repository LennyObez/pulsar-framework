<?php

declare(strict_types=1);

namespace Pulsar\Mail\Transport;

use Pulsar\Api\Internal;

/**
 * HTTP client contract for API-based mail transports.
 *
 * Transports build the request payload and delegate actual HTTP calls
 * to an implementation of this interface, keeping transport logic
 * testable without real network I/O.
 */
#[Internal]
interface MailHttpClientInterface
{
    /**
     * Send an HTTP request and return the response body.
     *
     * @param string               $method  HTTP method (POST, etc.)
     * @param string               $url     Full URL
     * @param array<string,string> $headers Request headers
     * @param string               $body    Request body (JSON, form data, etc.)
     *
     * @return MailHttpResponse The HTTP response
     */
    public function request(string $method, string $url, array $headers, string $body): MailHttpResponse;
}
