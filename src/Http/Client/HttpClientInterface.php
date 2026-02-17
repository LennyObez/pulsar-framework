<?php

declare(strict_types=1);

namespace Pulsar\Http\Client;

use Pulsar\Api\Api;

/**
 * Contract for HTTP client implementations.
 *
 * All methods accept a URL (absolute or relative to the configured base URL)
 * and an optional array of request options.
 */
#[Api(since: '1.0.0')]
interface HttpClientInterface
{
    /**
     * Send a GET request.
     *
     * @param array<string, mixed> $options Request options (headers, query, etc.)
     *
     * @throws HttpClientException On connection failure, timeout, or SSRF block
     */
    public function get(string $url, array $options = []): HttpResponse;

    /**
     * Send a POST request.
     *
     * @param array<string, mixed> $options Request options (headers, body, json, form, etc.)
     *
     * @throws HttpClientException On connection failure, timeout, or SSRF block
     */
    public function post(string $url, array $options = []): HttpResponse;

    /**
     * Send a PUT request.
     *
     * @param array<string, mixed> $options Request options
     *
     * @throws HttpClientException On connection failure, timeout, or SSRF block
     */
    public function put(string $url, array $options = []): HttpResponse;

    /**
     * Send a PATCH request.
     *
     * @param array<string, mixed> $options Request options
     *
     * @throws HttpClientException On connection failure, timeout, or SSRF block
     */
    public function patch(string $url, array $options = []): HttpResponse;

    /**
     * Send a DELETE request.
     *
     * @param array<string, mixed> $options Request options
     *
     * @throws HttpClientException On connection failure, timeout, or SSRF block
     */
    public function delete(string $url, array $options = []): HttpResponse;

    /**
     * Send a HEAD request.
     *
     * @param array<string, mixed> $options Request options
     *
     * @throws HttpClientException On connection failure, timeout, or SSRF block
     */
    public function head(string $url, array $options = []): HttpResponse;

    /**
     * Send an OPTIONS request.
     *
     * @param array<string, mixed> $options Request options
     *
     * @throws HttpClientException On connection failure, timeout, or SSRF block
     */
    public function options(string $url, array $options = []): HttpResponse;
}
