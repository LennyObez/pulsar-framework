<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Features\MapIdentity;

use Pulsar\Extension\SocialSso\Domain\OAuthTokenSet;

/**
 * Request DTO for mapping an OAuth token set to a social identity.
 */
final readonly class MapIdentityRequest
{
    public function __construct(
        public OAuthTokenSet $tokenSet,
        public string $providerName,
    ) {}
}
