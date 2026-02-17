<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Social\Internal\Linker;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Auth\Social\Contracts\SocialIdentityLinkerInterface;
use Pulsar\Extension\Auth\Social\Domain\LinkAction;
use Pulsar\Extension\Auth\Social\Domain\LinkedIdentityResult;
use Pulsar\Extension\Auth\Social\Domain\SocialIdentity;

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
