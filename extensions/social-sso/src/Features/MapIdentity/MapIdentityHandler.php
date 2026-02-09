<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Features\MapIdentity;

use Pulsar\Extension\SocialSso\Contracts\OAuthProviderRegistryInterface;

/**
 * Maps an OAuth token set to a normalized social identity.
 *
 * Delegates to the provider adapter to extract user information
 * from the token set into a canonical SocialIdentity structure.
 */
final readonly class MapIdentityHandler
{
    public function __construct(
        private OAuthProviderRegistryInterface $providerRegistry,
    ) {}

    public function handle(MapIdentityRequest $request): MapIdentityResult
    {
        $provider = $this->providerRegistry->get($request->providerName);

        $socialIdentity = $provider->mapIdentity($request->tokenSet);

        return new MapIdentityResult(
            socialIdentity: $socialIdentity,
        );
    }
}
