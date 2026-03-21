<?php

declare(strict_types=1);

namespace Pulsar\Routing;

use Pulsar\Api\Api;

use function in_array;

/**
 * Resolved domain context for the current request.
 *
 * Carries the domain, subdomain, and extension scope information
 * determined by the SubdomainRoutingMiddleware. Attached to the
 * request as an attribute for downstream consumers.
 */
#[Api(since: '1.0.0')]
final readonly class DomainContext
{
    /**
     * @param string $domain The full domain (e.g., 'forum.example.com')
     * @param string $subdomain The subdomain portion (e.g., 'forum'), empty for root domain
     * @param list<string> $extensionScopes Extension scopes bound to this subdomain
     * @param bool $isDefault Whether this is the default/root domain (no subdomain mapping)
     */
    public function __construct(
        public string $domain,
        public string $subdomain,
        public array $extensionScopes,
        public bool $isDefault,
    ) {}

    /**
     * Check if a given extension scope is active for this domain context.
     */
    public function hasScope(string $scope): bool
    {
        if ($this->isDefault) {
            return true;
        }

        return in_array($scope, $this->extensionScopes, true);
    }
}
