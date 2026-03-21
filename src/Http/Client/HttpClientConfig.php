<?php

declare(strict_types=1);

namespace Pulsar\Http\Client;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Configuration for the HTTP client.
 *
 * Immutable value object using clone-with for modifications.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class HttpClientConfig
{
    /**
     * @param float $timeout Connection + response timeout in seconds
     * @param int $retries Maximum number of retry attempts for failed requests
     * @param float $retryDelay Base delay between retries in seconds (exponential backoff applied)
     * @param string|null $baseUrl Base URL prepended to relative paths
     * @param bool $verifySsl Whether to verify SSL/TLS certificates
     * @param string|null $proxy Proxy URL (e.g. 'http://proxy:8080')
     * @param bool $ssrfProtection Whether to block requests to private/reserved IP ranges
     * @param int $maxRedirects Maximum number of redirects to follow (0 disables)
     * @param int $maxResponseSize Maximum response body size in bytes (0 = unlimited)
     * @param array<string, string> $defaultHeaders Headers applied to every request
     */
    public function __construct(
        public float $timeout = 30.0,
        public int $retries = 0,
        public float $retryDelay = 1.0,
        public ?string $baseUrl = null,
        public bool $verifySsl = true,
        public ?string $proxy = null,
        public bool $ssrfProtection = true,
        public int $maxRedirects = 5,
        public int $maxResponseSize = 10_485_760,
        public array $defaultHeaders = [],
    ) {}

    /**
     * Create from an associative array (e.g. config file values).
     *
     * @param array<string, mixed> $values
     */
    #[NoDiscard]
    public static function fromArray(array $values): self
    {
        /** @var int|float|string $timeout */
        $timeout = $values['timeout'] ?? 30.0;
        /** @var int|string $retries */
        $retries = $values['retries'] ?? 0;
        /** @var int|float|string $retryDelay */
        $retryDelay = $values['retry_delay'] ?? 1.0;
        /** @var int|string $maxRedirects */
        $maxRedirects = $values['max_redirects'] ?? 5;
        /** @var int|string $maxResponseSize */
        $maxResponseSize = $values['max_response_size'] ?? 10_485_760;
        /** @var array<string, string> $defaultHeaders */
        $defaultHeaders = $values['default_headers'] ?? [];

        return new self(
            timeout: (float) $timeout,
            retries: (int) $retries,
            retryDelay: (float) $retryDelay,
            baseUrl: isset($values['base_url']) && is_string($values['base_url']) ? $values['base_url'] : null,
            verifySsl: (bool) ($values['verify_ssl'] ?? true),
            proxy: isset($values['proxy']) && is_string($values['proxy']) ? $values['proxy'] : null,
            ssrfProtection: (bool) ($values['ssrf_protection'] ?? true),
            maxRedirects: (int) $maxRedirects,
            maxResponseSize: (int) $maxResponseSize,
            defaultHeaders: $defaultHeaders,
        );
    }

    /**
     * Return a new config with the given timeout.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withTimeout(float $timeout): self
    {
        return clone($this, ['timeout' => $timeout]);
    }

    /**
     * Return a new config with the given retry settings.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withRetries(int $retries, float $delay = 1.0): self
    {
        return clone($this, ['retries' => $retries, 'retryDelay' => $delay]);
    }

    /**
     * Return a new config with the given base URL.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withBaseUrl(string $baseUrl): self
    {
        return clone($this, ['baseUrl' => rtrim($baseUrl, '/')]);
    }

    /**
     * Return a new config with SSRF protection toggled.
     *
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    #[NoDiscard]
    public function withSsrfProtection(bool $enabled): self
    {
        return clone($this, ['ssrfProtection' => $enabled]);
    }
}
