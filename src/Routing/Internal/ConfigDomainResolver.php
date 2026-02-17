<?php

declare(strict_types=1);

namespace Pulsar\Routing\Internal;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\DomainConfig;
use Pulsar\Routing\DomainContext;
use Pulsar\Routing\DomainResolverInterface;

use function explode;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Resolves domain context from request headers and DomainConfig.
 *
 * Supports reverse proxy setups via X-Forwarded-Host. Extracts
 * the subdomain by stripping the configured default domain from
 * the request host, then maps it to extension scopes.
 */
#[Internal]
final readonly class ConfigDomainResolver implements DomainResolverInterface
{
    public function __construct(
        private DomainConfig $config,
    ) {}

    public function resolve(ServerRequestInterface $request): DomainContext
    {
        $host = $this->extractHost($request);

        // Strip port if present
        $colonPos = strpos($host, ':');

        if ($colonPos !== false) {
            $host = substr($host, 0, $colonPos);
        }

        $host = strtolower($host);
        $defaultDomain = strtolower($this->config->defaultDomain);

        // No subdomain mappings configured; everything is default domain
        if (!$this->config->hasSubdomainMappings()) {
            return new DomainContext(
                domain: $host,
                subdomain: '',
                extensionScopes: [],
                isDefault: true,
            );
        }

        // Exact match on default domain: no subdomain
        if ($host === $defaultDomain) {
            return new DomainContext(
                domain: $host,
                subdomain: '',
                extensionScopes: [],
                isDefault: true,
            );
        }

        // Extract subdomain: e.g., 'forum.example.com' - 'example.com' = 'forum'
        $suffix = '.' . $defaultDomain;

        if (str_ends_with($host, $suffix)) {
            $subdomain = substr($host, 0, -strlen($suffix));

            // Look up scopes for this subdomain
            $scopes = $this->config->scopesForSubdomain($subdomain);

            if ($scopes !== null) {
                return new DomainContext(
                    domain: $host,
                    subdomain: $subdomain,
                    extensionScopes: $scopes,
                    isDefault: false,
                );
            }
        }

        // Host doesn't match any configured subdomain; treat as default
        return new DomainContext(
            domain: $host,
            subdomain: '',
            extensionScopes: [],
            isDefault: true,
        );
    }

    /**
     * Extract the effective host from the request, preferring X-Forwarded-Host
     * for reverse proxy support.
     */
    private function extractHost(ServerRequestInterface $request): string
    {
        // Prefer X-Forwarded-Host for reverse proxy support
        $forwarded = $request->getHeaderLine('X-Forwarded-Host');

        if ($forwarded !== '') {
            // X-Forwarded-Host may contain multiple hosts (comma-separated);
            // use the first (closest to the client).
            $parts = explode(',', $forwarded);

            return trim($parts[0]);
        }

        $uri = $request->getUri();

        return $uri->getHost();
    }
}
