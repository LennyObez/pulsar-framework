<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Features\MapIdentity;

use Pulsar\Extension\Auth\Social\Contracts\OAuthProviderRegistryInterface;

/**
 * Maps an OAuth token set to a normalized social identity.
 *
 * Delegates to the provider adapter to extract user information
 * from the token set into a canonical SocialIdentity structure.
 */
final readonly class MapIdentityHandler
{
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
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
