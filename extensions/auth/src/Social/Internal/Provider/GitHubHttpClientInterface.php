<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Internal\Provider;

use Pulsar\Api\Internal;

/**
 * HTTP client abstraction for the GitHub OAuth provider.
 *
 * This enables testing without real HTTP calls while keeping the
 * provider implementation independent of any specific HTTP library.
 */
#[Internal]
interface GitHubHttpClientInterface
{
    /**
     * Send a POST request.
     *
     * @param array<string, string> $params Form parameters
     * @param array<string, string> $headers Request headers
     * @return string Response body
     */
    public function post(string $url, array $params, array $headers = []): string;

    /**
     * Send a GET request.
     *
     * @param array<string, string> $headers Request headers
     * @return string Response body
     */
    public function get(string $url, array $headers = []): string;
}
