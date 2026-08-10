<?php

declare(strict_types=1);

namespace Pulsar\Routing\Internal;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\DomainConfig;
use Pulsar\Http\TrustedProxy;
use Pulsar\Routing\DomainContext;
use Pulsar\Routing\DomainResolverInterface;

use function explode;
use function is_string;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Resolves domain context from request headers and DomainConfig.
 *
 * Supports reverse proxy setups via X-Forwarded-Host. Extracts
 * the subdomain by stripping the configured default domain from
 * the request host, then maps it to extension scopes.
 *
 * X-Forwarded-Host is only honored when the request originates
 * from a trusted proxy (per TrustedProxy::isTrustedSource). Otherwise the
 * authoritative host comes from the request URI's Host header. Without the
 * gate, any client could spoof the routing target by sending an arbitrary
 * X-Forwarded-Host, breaking multi-tenant isolation.
 */
#[Internal]
final readonly class ConfigDomainResolver implements DomainResolverInterface
{
    public function __construct(
        private DomainConfig $config,
        private ?TrustedProxy $trustedProxy = null,
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
     * Extract the effective host from the request, honoring X-Forwarded-Host
     * only when the request originates from a trusted proxy.
     */
    private function extractHost(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-Host');

        if ($forwarded !== '' && $this->isRequestFromTrustedProxy($request)) {
            // X-Forwarded-Host may contain multiple hosts (comma-separated);
            // use the first (closest to the client).
            $parts = explode(',', $forwarded);

            return trim($parts[0]);
        }

        $uri = $request->getUri();

        return $uri->getHost();
    }

    /**
     * Decide whether to trust forwarded headers from this request.
     *
     * Returns true only when (a) a TrustedProxy chain is configured and (b) the
     * direct REMOTE_ADDR is in that chain. Without a configured TrustedProxy
     * the resolver falls back to the URI host, refusing X-Forwarded-Host
     * entirely (fail-closed for the multi-tenant routing path).
     */
    private function isRequestFromTrustedProxy(ServerRequestInterface $request): bool
    {
        if ($this->trustedProxy === null) {
            return false;
        }

        $server = $request->getServerParams();
        $remote = $server['REMOTE_ADDR'] ?? null;

        if (!is_string($remote) || $remote === '') {
            return false;
        }

        return $this->trustedProxy->isTrustedSource($remote);
    }
}
