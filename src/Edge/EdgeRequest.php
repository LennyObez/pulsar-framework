<?php

declare(strict_types=1);

namespace Pulsar\Edge;

use Pulsar\Api\Api;

/**
 * Lightweight request representation for edge functions.
 *
 * Contains only the information available at the CDN edge:
 * URL, headers, cookies, and geo/client metadata.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EdgeRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, string> $geo Geo data (country, region, city, etc.)
     */
    public function __construct(
        public string $method,
        public string $url,
        public string $path,
        public array $headers = [],
        public array $cookies = [],
        public array $geo = [],
        public string $ip = '',
        public string $userAgent = '',
    ) {}

    /**
     * Get a header value.
     */
    public function header(string $name): ?string
    {
        $lower = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $lower) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Get a cookie value.
     */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * Get a geo value (e.g. 'country', 'region', 'city').
     */
    public function geo(string $key): ?string
    {
        return $this->geo[$key] ?? null;
    }

    /**
     * Get the country code from geo data.
     */
    public function country(): ?string
    {
        return $this->geo['country'] ?? null;
    }
}
