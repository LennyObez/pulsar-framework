<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;
use function is_array;
use function is_bool;
use function is_string;

/**
 * Typed configuration DTO for multi-domain/subdomain routing.
 *
 * Maps from `config/domains.php`. Subdomain-to-extension mapping is
 * opt-in: by default all extensions serve on the same domain.
 */
#[Api(since: '1.0.0')]
final readonly class DomainConfig
{
    /**
     * @param string $defaultDomain The primary application domain (e.g., 'example.com')
     * @param array<string, list<string>> $subdomains Map of subdomain prefix to extension scopes
     * @param bool $corsAcrossSubdomains Whether to allow CORS across sibling subdomains
     * @param string $sharedSessionDomain Cookie domain for cross-subdomain sessions (e.g., '.example.com')
     * @param string $scheme URL scheme for generated URLs ('https' or 'http')
     */
    public function __construct(
        public string $defaultDomain = 'localhost',
        public array $subdomains = [],
        public bool $corsAcrossSubdomains = true,
        public string $sharedSessionDomain = '',
        public string $scheme = 'https',
    ) {}

    /**
     * Build from a raw config array.
     *
     * @param array<string, mixed> $data Raw array from config/domains.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $rawDomain = $data['default_domain'] ?? 'localhost';
        $defaultDomain = $environment->get('APP_DOMAIN')
            ?? (is_string($rawDomain) ? $rawDomain : 'localhost');

        $rawSubdomains = $data['subdomains'] ?? [];
        $subdomains = [];

        if (is_array($rawSubdomains)) {
            foreach ($rawSubdomains as $subdomain => $scopes) {
                if (!is_string($subdomain)) {
                    continue;
                }

                if (is_string($scopes)) {
                    $subdomains[$subdomain] = [$scopes];
                } elseif (is_array($scopes)) {
                    /** @var list<string> $filtered */
                    $filtered = array_values(array_filter(
                        $scopes,
                        static fn(mixed $v): bool => is_string($v),
                    ));
                    $subdomains[$subdomain] = $filtered;
                }
            }
        }

        $corsAcrossSubdomains = isset($data['cors_across_subdomains'])
            ? (is_bool($data['cors_across_subdomains']) ? $data['cors_across_subdomains'] : true)
            : true;

        $rawSessionDomain = $data['shared_session_domain'] ?? '';
        $sharedSessionDomain = $environment->get('SESSION_DOMAIN')
            ?? (is_string($rawSessionDomain) ? $rawSessionDomain : '');

        $rawScheme = $data['scheme'] ?? 'https';
        $scheme = is_string($rawScheme) ? $rawScheme : 'https';

        return new self(
            defaultDomain: $defaultDomain,
            subdomains: $subdomains,
            corsAcrossSubdomains: $corsAcrossSubdomains,
            sharedSessionDomain: $sharedSessionDomain,
            scheme: $scheme,
        );
    }

    /**
     * Whether subdomain routing is configured (any subdomain mappings exist).
     */
    public function hasSubdomainMappings(): bool
    {
        return $this->subdomains !== [];
    }

    /**
     * Get the extension scopes for a given subdomain.
     *
     * Returns null if no mapping exists for the subdomain.
     *
     * @return list<string>|null
     */
    public function scopesForSubdomain(string $subdomain): ?array
    {
        return $this->subdomains[$subdomain] ?? null;
    }

    /**
     * Find which subdomain a given scope is mapped to.
     *
     * Returns null if the scope is not mapped to any subdomain.
     */
    public function subdomainForScope(string $scope): ?string
    {
        foreach ($this->subdomains as $subdomain => $scopes) {
            if (in_array($scope, $scopes, true)) {
                return $subdomain;
            }
        }

        return null;
    }
}
