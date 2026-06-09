<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function array_filter;
use function array_values;
use function in_array;
use function is_array;
use function is_string;

/**
 * Typed configuration DTO for multi-domain/subdomain routing.
 *
 * Maps from `config/domains.php`. Subdomain-to-extension mapping is
 * opt-in: by default all extensions serve on the same domain.
 * @api
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
     * @param array{
     *     default_domain?: string,
     *     subdomains?: array<string, string|array<array-key, mixed>>,
     *     cors_across_subdomains?: bool,
     *     shared_session_domain?: string,
     *     scheme?: string,
     * } $data Raw array from config/domains.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $subdomains = [];
        $rawSubs = $data['subdomains'] ?? null;
        if (is_array($rawSubs)) {
            foreach ($rawSubs as $subdomain => $scopes) {
                if (!is_string($subdomain)) {
                    continue;
                }
                if (is_string($scopes)) {
                    $subdomains[$subdomain] = [$scopes];
                } elseif (is_array($scopes)) {
                    $subdomains[$subdomain] = array_values(array_filter($scopes, is_string(...)));
                }
            }
        }

        $domainEnv = $environment->get('APP_DOMAIN');
        $sessionEnv = $environment->get('SESSION_DOMAIN');

        return new self(
            defaultDomain: $domainEnv ?? Coerce::string($data['default_domain'] ?? null, 'localhost'),
            subdomains: $subdomains,
            corsAcrossSubdomains: Coerce::strictBool($data['cors_across_subdomains'] ?? null, true),
            sharedSessionDomain: $sessionEnv ?? Coerce::string($data['shared_session_domain'] ?? null),
            scheme: Coerce::string($data['scheme'] ?? null, 'https'),
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
