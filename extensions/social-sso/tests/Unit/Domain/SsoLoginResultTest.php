<?php

declare(strict_types=1);

namespace Pulsar\Extension\SocialSso\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\IdTokenClaims;
use Pulsar\Extension\SocialSso\Domain\LinkAction;
use Pulsar\Extension\SocialSso\Domain\LinkedIdentityResult;
use Pulsar\Extension\SocialSso\Domain\SocialIdentity;
use Pulsar\Extension\SocialSso\Domain\SsoLoginResult;

final class SsoLoginResultTest extends TestCase
{
    #[Test]
    public function constructsWithoutVerifiedClaims(): void
    {
        $identity = new SocialIdentity('github', 'u1');
        $link = new LinkedIdentityResult(true, 'id-1', LinkAction::Linked);

        $result = new SsoLoginResult($identity, $link);

        self::assertSame($identity, $result->socialIdentity);
        self::assertSame($link, $result->linkResult);
        self::assertNull($result->verifiedClaims);
    }

    #[Test]
    public function constructsWithVerifiedClaims(): void
    {
        $identity = new SocialIdentity('google', 'g1');
        $link = new LinkedIdentityResult(true, 'id-2', LinkAction::Created);
        $claims = new IdTokenClaims('g1', 'https://iss', 'client', 99999, 99998);

        $result = new SsoLoginResult($identity, $link, $claims);

        self::assertSame($claims, $result->verifiedClaims);
    }
}
