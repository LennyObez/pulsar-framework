<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Features\MapIdentity;

use Pulsar\Extension\Auth\Social\Domain\SocialIdentity;

/**
 * Result DTO for mapping an OAuth token set to a social identity.
 */
final readonly class MapIdentityResult
{
    public function __construct(
        public SocialIdentity $socialIdentity,
    ) {}
}
