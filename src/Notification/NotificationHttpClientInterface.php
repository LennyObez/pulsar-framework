<?php

declare(strict_types=1);

namespace Pulsar\Notification;

use Pulsar\Api\Internal;

/**
 * HTTP client contract for notification channels that make outbound HTTP calls.
 *
 * Keeps channel logic testable without real network I/O.
 */
#[Internal]
interface NotificationHttpClientInterface
{
    /**
     * Send an HTTP request and return the response status code.
     *
     * @param string               $method  HTTP method (POST, PUT, etc.)
     * @param string               $url     Full URL
     * @param array<string, string> $headers Request headers
     * @param string               $body    Request body
     *
     * @return int HTTP response status code
     */
    public function request(string $method, string $url, array $headers, string $body): int;
}
