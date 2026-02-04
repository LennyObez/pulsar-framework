<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;
use Pulsar\Extension\SocialSso\Domain\LinkAction;
use Pulsar\Extension\SocialSso\Domain\LinkedIdentityResult;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Domain\SsoLoginResult;

#[CoversClass(SsoLoginResult::class)]
final class SsoLoginResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllFields(): void
    {
        $identity = new SocialIdentity(provider: 'google', providerUserId: '123');
        $linkResult = new LinkedIdentityResult(linked: true, identityId: 'user-1', action: LinkAction::Linked);
        $claims = new IdTokenClaims(sub: '123', iss: 'https://issuer.com', aud: 'client', exp: 9999999999, iat: 1000000000);

        $result = new SsoLoginResult(
            socialIdentity: $identity,
            linkResult: $linkResult,
            verifiedClaims: $claims,
        );

        self::assertSame($identity, $result->socialIdentity);
        self::assertSame($linkResult, $result->linkResult);
        self::assertSame($claims, $result->verifiedClaims);
    }

    #[Test]
    public function verifiedClaimsDefaultsToNull(): void
    {
        $identity = new SocialIdentity(provider: 'github', providerUserId: '456');
        $linkResult = new LinkedIdentityResult(linked: false, identityId: null, action: LinkAction::Unlinked);

        $result = new SsoLoginResult(
            socialIdentity: $identity,
            linkResult: $linkResult,
        );

        self::assertNull($result->verifiedClaims);
    }
}
