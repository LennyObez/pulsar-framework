<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Internal\Linker;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\SocialSso\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\SocialSso\Domain\LinkAction;
use Pulsar\Extension\SocialSso\Domain\LinkedIdentityResult;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;

/**
 * No-op social identity linker.
 *
 * Always returns an unlinked result. Applications must replace this with a
 * concrete linker that integrates with their user management system.
 */
#[Internal]
final readonly class NullSocialIdentityLinker implements SocialIdentityLinkerInterface
{
    #[Override]
    public function link(SocialIdentity $socialIdentity): LinkedIdentityResult
    {
        return new LinkedIdentityResult(
            linked: false,
            identityId: null,
            action: LinkAction::Unlinked,
        );
    }
}
