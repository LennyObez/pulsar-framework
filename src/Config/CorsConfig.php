<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function trim;

/**
 * Typed configuration DTO for CORS (Cross-Origin Resource Sharing).
 *
 * Maps from `config/security.php` under the `cors` key.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CorsConfig
{
    /**
     * @param bool $enabled Whether CORS handling is active
     * @param list<string> $allowedOrigins Allowed origin patterns (e.g., 'https://example.com')
     * @param list<string> $allowedMethods HTTP methods allowed in CORS requests
     * @param list<string> $allowedHeaders Headers the client may send
     * @param list<string> $exposedHeaders Headers the client may read from the response
     * @param bool $allowCredentials Whether cookies/auth headers are allowed
     * @param int $maxAge Preflight cache duration in seconds
     */
    public function __construct(
        public bool $enabled = false,
        public array $allowedOrigins = [],
        public array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        public array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-Token'],
        public array $exposedHeaders = [],
        public bool $allowCredentials = false,
        public int $maxAge = 0,
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     allowed_origins?: list<string>|string,
     *     allowed_methods?: list<string>|string,
     *     allowed_headers?: list<string>|string,
     *     exposed_headers?: list<string>|string,
     *     allow_credentials?: bool|int|string,
     *     max_age?: int,
     * } $data Raw `cors` sub-array from config
     *
     * @throws ConfigException If allowedOrigins=['*'] combined with allowCredentials=true
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $enabled = isset($data['enabled']) && $data['enabled'] === true;
        $origins = self::normalizeStringList($data['allowed_origins'] ?? []);
        $allowCredentials = isset($data['allow_credentials']) && $data['allow_credentials'] === true;

        // CORS spec forbids Access-Control-Allow-Origin: * with credentials.
        // Browsers will reject the response, and it exposes all origins to
        // credential-bearing requests: a security risk.
        if ($enabled && $allowCredentials && in_array('*', $origins, true)) {
            throw ConfigException::invalidValue(
                'security.cors',
                'allowedOrigins cannot be ["*"] when allowCredentials is true. '
                . 'Specify explicit origin(s) instead.',
            );
        }

        return new self(
            enabled: $enabled,
            allowedOrigins: $origins,
            allowedMethods: self::normalizeStringList(
                $data['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            ),
            allowedHeaders: self::normalizeStringList(
                $data['allowed_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-Token'],
            ),
            exposedHeaders: self::normalizeStringList($data['exposed_headers'] ?? []),
            allowCredentials: $allowCredentials,
            maxAge: $data['max_age'] ?? 0,
        );
    }

    /**
     * Check whether the given origin is allowed.
     */
    public function isOriginAllowed(string $origin): bool
    {
        if ($this->allowedOrigins === ['*']) {
            return true;
        }

        // Empty allowedOrigins means deny all: explicit origins required
        if ($this->allowedOrigins === []) {
            return false;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(
                array_map(trim(...), explode(',', $value)),
                static fn(string $s): bool => $s !== '',
            ));
        }

        if (!is_array($value)) {
            return [];
        }

        /** @var list<string> */
        return array_values(array_filter(
            array_map(
                static fn(mixed $v): string => is_string($v) ? trim($v) : '',
                $value,
            ),
            static fn(string $s): bool => $s !== '',
        ));
    }
}
